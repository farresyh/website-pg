<?php

namespace App\Services\Reseller;

use App\Models\AffiliateUser;
use App\Models\CustomerNotification;
use App\Models\LedgerEntry;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Models\ResellerDormancyNotice;
use App\Models\ResellerStatusChange;
use App\Models\WalletTopupAttempt;
use App\Models\WhatsAppGroup;
use App\Services\Auth\AccountOwnerType;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Membership\PlunkMailer;
use App\Services\Membership\PlunkSendException;
use App\Services\Notification\WhatsAppOutbox;
use App\Services\Reseller\Bot\ResellerBotReplyFormatter;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ADR-142: a reseller with no transaction for the set number of days is
 * warned, then deactivated through ResellerAccountStatus.
 *
 * The idle spell is measured from the anchor: the latest of the last
 * transaction (a `wallet_debit` or `wallet_topup` ledger entry, decision 19),
 * approval, the last reactivation, and the day the switch was turned on, in
 * Malaysia calendar days (decision 20). Nothing about activity is stored; it
 * is read from the ledger each run.
 *
 * A reseller is deactivated only after a warning for the same anchor went out
 * at least the warning lead earlier (decision 21), never while a top-up is
 * pending (decision 22), and the anchor is re-read under the wallet lock
 * before the write (decision 23), so an order that lands meanwhile wins.
 */
final class ResellerDormancyService
{
    public function __construct(
        private readonly ResellerAccountStatus $accountStatus,
        private readonly LedgerService $ledger,
        private readonly WhatsAppOutbox $outbox,
        private readonly PlunkMailer $mailer,
        private readonly BackupFailureAlerter $alerter,
    ) {}

    /** @return array{warned: list<Reseller>, deactivated: list<Reseller>} */
    public function run(?CarbonImmutable $now = null): array
    {
        $settings = PlatformSettings::current();
        $result = ['warned' => [], 'deactivated' => []];

        if (! $settings->reseller_dormancy_enabled) {
            return $result;
        }

        $now ??= CarbonImmutable::now();
        $today = $now->setTimezone(MalaysiaTime::TIMEZONE)->startOfDay();
        $days = $settings->reseller_dormancy_days;
        $lead = $settings->reseller_dormancy_warning_days;

        $resellers = Reseller::query()->where('is_active', true)->whereNull('internal_reason')->orderBy('id')->get();
        $anchors = $this->anchors($resellers, $settings);

        foreach ($resellers as $reseller) {
            $anchor = $anchors[$reseller->id];
            $idle = (int) $anchor->diffInDays($today);

            if ($idle < $days - $lead) {
                continue;
            }

            $warning = $this->notice($reseller, ResellerDormancyNotice::WARNING, $anchor);
            if ($warning === null) {
                $this->record($reseller, ResellerDormancyNotice::WARNING, $anchor);
                $this->sendWarning($reseller, $anchor, $days, $balance = $this->ledger->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
                $result['warned'][] = $reseller;

                continue;
            }

            $warnedOn = CarbonImmutable::parse($warning->created_at)->setTimezone(MalaysiaTime::TIMEZONE)->startOfDay();
            if ($idle < $days || (int) $warnedOn->diffInDays($today) < $lead || $this->hasPendingTopup($reseller)) {
                continue;
            }

            $deactivated = $this->accountStatus->deactivate(
                $reseller,
                ResellerDeactivationReason::Dormant,
                null,
                // Re-read under the wallet lock: a transaction that landed since moves the anchor.
                fn (Reseller $locked) => $this->anchors(collect([$locked]), $settings)[$locked->id]->equalTo($anchor)
                    && ! $this->hasPendingTopup($locked),
            );

            if ($deactivated) {
                $this->record($reseller, ResellerDormancyNotice::DEACTIVATED, $anchor);
                $this->sendDeactivated($reseller, $anchor, $days, $this->ledger->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
                $result['deactivated'][] = $reseller;
            }
        }

        $this->digest($result, $days);

        return $result;
    }

    /**
     * The Malaysia date each reseller's idle spell starts from (decision 20).
     *
     * @param  Collection<int, Reseller>  $resellers
     * @return array<int, CarbonImmutable>
     */
    private function anchors(Collection $resellers, PlatformSettings $settings): array
    {
        $ids = $resellers->pluck('id')->all();

        $activity = LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
            ->whereIn('owner_id', $ids)
            ->whereIn('type', [WalletEntryType::Debit->value, WalletEntryType::Topup->value])
            ->groupBy('owner_id')
            ->selectRaw('owner_id, MAX(created_at) AS at')
            ->pluck('at', 'owner_id');

        $reactivated = ResellerStatusChange::query()
            ->whereIn('reseller_id', $ids)
            ->where('is_active', true)
            ->groupBy('reseller_id')
            ->selectRaw('reseller_id, MAX(created_at) AS at')
            ->pluck('at', 'reseller_id');

        return $resellers->mapWithKeys(fn (Reseller $r) => [$r->id => collect([
            $r->created_at,
            $activity[$r->id] ?? null,
            $reactivated[$r->id] ?? null,
            $settings->reseller_dormancy_enabled_at,
        ])->filter()
            ->map(fn ($at) => CarbonImmutable::parse($at, 'UTC')->setTimezone(MalaysiaTime::TIMEZONE)->startOfDay())
            ->max()])->all();
    }

    private function hasPendingTopup(Reseller $reseller): bool
    {
        return WalletTopupAttempt::query()
            ->where('reseller_id', $reseller->id)
            ->where('status', WalletTopupAttemptStatus::Pending->value)
            ->exists();
    }

    private function notice(Reseller $reseller, string $kind, CarbonImmutable $anchor): ?ResellerDormancyNotice
    {
        return ResellerDormancyNotice::query()
            ->where('reseller_id', $reseller->id)->where('kind', $kind)->whereDate('anchor_on', $anchor->toDateString())
            ->first();
    }

    private function record(Reseller $reseller, string $kind, CarbonImmutable $anchor): void
    {
        try {
            ResellerDormancyNotice::query()->create(['reseller_id' => $reseller->id, 'kind' => $kind, 'anchor_on' => $anchor->toDateString()]);
        } catch (UniqueConstraintViolationException) {
            // A second run raced this one; the unique key keeps one row.
        }
    }

    private function sendWarning(Reseller $reseller, CarbonImmutable $anchor, int $days, int $balanceSen): void
    {
        $offOn = $anchor->addDays($days)->locale('ms')->translatedFormat('j F Y');
        $balance = 'RM'.ResellerBotReplyFormatter::formatSen($balanceSen);

        $this->tell(
            $reseller,
            ResellerDormancyNotice::WARNING,
            $anchor,
            "「 AMARAN AKAUN 」\n\nTiada transaksi dalam akaun reseller anda sejak beberapa hari. Akaun akan dinyahaktifkan pada {$offOn} jika tiada order atau tambah baki sebelum itu.\n\nBaki Pembelian anda ({$balance}) kekal selamat.",
            'Your PekanGame reseller account will be deactivated soon',
            ['offOn' => $anchor->addDays($days)->format('j F Y'), 'days' => $days, 'balance' => $balance, 'deactivated' => false],
        );
    }

    private function sendDeactivated(Reseller $reseller, CarbonImmutable $anchor, int $days, int $balanceSen): void
    {
        $balance = 'RM'.ResellerBotReplyFormatter::formatSen($balanceSen);
        $denial = $this->accountStatus->denial($reseller);

        $this->tell(
            $reseller,
            ResellerDormancyNotice::DEACTIVATED,
            $anchor,
            "「 AKAUN DINYAHAKTIFKAN 」\n\n".($denial?->messageMs ?? '')."\n\nBaki Pembelian anda ({$balance}) kekal selamat.",
            'Your PekanGame reseller account has been deactivated',
            ['days' => $days, 'balance' => $balance, 'deactivated' => true, 'wayBack' => $denial?->message ?? ''],
        );
    }

    /**
     * Each active bot group through the outbox (deduplicated per notice), and
     * email to the contact address and the active portal logins. A failed send
     * is logged; the deactivation that follows the lead never waits on it.
     *
     * @param  array<string, mixed>  $view
     */
    private function tell(Reseller $reseller, string $kind, CarbonImmutable $anchor, string $whatsapp, string $subject, array $view): void
    {
        $configured = config('services.openwa.session_id') !== null && config('services.openwa.api_key') !== null;

        WhatsAppGroup::query()->where('owner_type', WhatsAppGroup::OWNER_RESELLER)->where('owner_id', $reseller->id)->where('is_active', true)
            ->orderBy('id')->get()
            ->each(fn (WhatsAppGroup $group) => $this->outbox->enqueue(
                session: CustomerNotification::SESSION_BOT,
                event: "reseller_dormancy_{$kind}",
                dedupeKey: "reseller-dormancy:{$kind}:{$reseller->id}:{$anchor->toDateString()}:{$group->id}",
                message: $whatsapp,
                chatId: $group->whatsapp_group_id,
                skipReason: $configured ? null : 'Bot WhatsApp session is not configured',
            ));

        $emails = AffiliateUser::query()
            ->where('owner_type', AccountOwnerType::Reseller->value)->where('owner_id', $reseller->id)
            ->where('is_active', true)->pluck('email')->all();
        if ($reseller->email !== null) {
            $emails[] = $reseller->email;
        }

        foreach (array_unique($emails) as $email) {
            try {
                $this->mailer->sendView($email, $subject, 'emails.reseller-dormancy', ['businessName' => $reseller->business_name] + $view);
            } catch (PlunkSendException $e) {
                Log::warning('Reseller dormancy email failed', ['reseller_id' => $reseller->id, 'kind' => $kind, 'error' => $e->getMessage()]);
            }
        }
    }

    /** @param array{warned: list<Reseller>, deactivated: list<Reseller>} $result */
    private function digest(array $result, int $days): void
    {
        if ($result['warned'] === [] && $result['deactivated'] === []) {
            return;
        }

        $line = fn (Reseller $r) => sprintf('- %s (#%d), balance RM%s', $r->business_name, $r->id, ResellerBotReplyFormatter::formatSen($this->ledger->balance(LedgerOwnerType::ResellerWallet, $r->id)));
        $body = '';
        if ($result['deactivated'] !== []) {
            $body .= "Deactivated today (no transaction for {$days} days):\n".implode("\n", array_map($line, $result['deactivated']))."\n\n";
        }
        if ($result['warned'] !== []) {
            $body .= "Warned today:\n".implode("\n", array_map($line, $result['warned']))."\n\n";
        }

        $this->alerter->alert(
            sprintf('%d reseller(s) deactivated, %d warned', count($result['deactivated']), count($result['warned'])),
            $body.'Reactivate one in Admin → Resellers when it asks. Settings: Admin → Settings → Reseller inactivity.',
            'Reseller inactivity',
        );
    }
}
