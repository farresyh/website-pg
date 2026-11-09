<?php

namespace App\Services\Rebate;

use App\Models\CustomerNotification;
use App\Models\Reseller;
use App\Models\ResellerRebateLadder;
use App\Models\ResellerRebateMilestone;
use App\Models\ResellerRebateMonth;
use App\Models\WhatsAppGroup;
use App\Services\Notification\WhatsAppOutbox;
use App\Services\Reseller\Bot\ResellerBotReplyFormatter;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * ADR-139 decisions 33, 40, 47 and the terms addendum's notice rule: the
 * rebate's messages to a reseller's WhatsApp groups, through the bot
 * session of WhatsAppOutbox. Run by a scheduled task (and the ladder save),
 * never from a money path, so a bot failure cannot touch money. Each message
 * goes once per group, by its dedupe key. Only active, eligible resellers
 * hear anything, and no message shows a share or a margin (decision 8).
 */
final class RebateNotifier
{
    public const EVENT_STEP = 'rebate_step_reached';

    public const EVENT_REMINDER = 'rebate_expiry_reminder';

    public const EVENT_LADDER = 'rebate_ladder_notice';

    /** Decision 33: one reminder this long before a reward lapses. */
    private const REMINDER_DAYS = 3;

    public function __construct(
        private readonly RebateEntitlement $entitlement,
        private readonly RebateMonthService $months,
        private readonly WhatsAppOutbox $outbox,
    ) {}

    public static function isRebateEvent(string $event): bool
    {
        return in_array($event, [self::EVENT_STEP, self::EVENT_REMINDER, self::EVENT_LADDER], true);
    }

    /** The every-10-minutes task: newly reached steps, then rewards about to lapse. */
    public function notifyProgress(): void
    {
        $this->announceSteps();
        $this->remind();
    }

    /** The terms addendum, decision 2: a saved version tells every eligible reseller when it starts. */
    public function ladderSaved(ResellerRebateLadder $ladder): void
    {
        $text = "「 JADUAL REBATE BARU 」\n\n"
            .'Jadual Program Rebate PekanGame yang baru berkuat kuasa mulai '.self::day($ladder->effective_from).'. '
            .'Semak ganjaran anda di portal:';

        Reseller::query()->where('is_active', true)->where('rebate_eligible', true)->orderBy('id')
            ->each(fn (Reseller $r) => $this->send($r, self::EVENT_LADDER, "rebate-ladder:{$ladder->id}:{$r->id}", $text));
    }

    /** Decision 40: once per reached, unclaimed step, for every month not finalised yet. */
    private function announceSteps(): void
    {
        $current = CarbonImmutable::now(MalaysiaTime::TIMEZONE)->startOfMonth();

        foreach ([$current->subMonthNoOverflow(), $current] as $month) {
            if (ResellerRebateMonth::query()->whereDate('period', $month->toDateString())->exists()) {
                continue; // finalised: its rows are announced by the reminder, if still unclaimed
            }
            [$year, $m] = [(int) $month->format('Y'), (int) $month->format('n')];

            // ponytail: one standing per reseller with sales that month, like liveTotalSen; group the reach query if resellers reach the hundreds.
            foreach ($this->months->resellerIds($year, $m) as $id) {
                $reseller = Reseller::query()->where('is_active', true)->where('rebate_eligible', true)->find($id);
                $standing = $reseller === null ? null : $this->entitlement->standing($reseller, $year, $m);
                if ($standing === null || ! $standing->earnsRebate()) {
                    continue;
                }

                $claimed = ResellerRebateMilestone::query()->where('reseller_id', $id)->whereDate('period', $month->toDateString())
                    ->where('state', ResellerRebateMilestone::CLAIMED)->pluck('step')->all();
                for ($step = 1; $step <= $standing->reachedStep; $step++) {
                    if (! in_array($step, $claimed, true)) {
                        $this->send($reseller, self::EVENT_STEP, "rebate-step:{$id}:{$month->format('Y-m')}:{$step}", self::stepText($standing, $step, $month));
                    }
                }
            }
        }
    }

    /** Decision 33: one message per reseller and month, once its unclaimed rewards are inside the last three days. */
    private function remind(): void
    {
        $now = CarbonImmutable::now(MalaysiaTime::TIMEZONE);

        ResellerRebateMilestone::query()
            ->where('state', ResellerRebateMilestone::AVAILABLE)->where('amount_sen', '>', 0)
            ->whereDate('period', '<=', ResellerRebateMilestone::lastPeriodDueBy($now->addDays(self::REMINDER_DAYS))->toDateString())
            ->get()
            ->groupBy(fn (ResellerRebateMilestone $row) => $row->reseller_id.'|'.$row->period->format('Y-m'))
            ->each(function ($rows) use ($now) {
                $first = $rows->first();
                $deadline = ResellerRebateMilestone::claimDeadline($first->period);
                $reseller = Reseller::query()->where('is_active', true)->where('rebate_eligible', true)->find($first->reseller_id);
                if ($reseller === null || $now->greaterThanOrEqualTo($deadline)) {
                    return;
                }

                $text = "「 PERINGATAN REBATE 」\n\n"
                    .'Ganjaran rebate RM'.ResellerBotReplyFormatter::formatSen((int) $rows->sum('amount_sen'))
                    .' bagi bulan '.self::month($first->period).' belum dituntut. '
                    .'Tuntut sebelum '.self::day($deadline->subDay()).', atau ia akan luput:';
                $this->send($reseller, self::EVENT_REMINDER, "rebate-reminder:{$reseller->id}:{$first->period->format('Y-m')}", $text);
            });
    }

    private static function stepText(RebateStanding $standing, int $step, CarbonImmutable $month): string
    {
        $reward = $standing->ladderCumulative($step) - $standing->ladderCumulative($step - 1);

        return "「 GANJARAN REBATE 」\n\n"
            .'Tahniah! Belian anda bulan '.self::month($month).' sudah mencapai RM'
            .ResellerBotReplyFormatter::formatSen((int) $standing->ladder->steps[$step - 1]['threshold_sen']).'. '
            .'Ganjaran RM'.ResellerBotReplyFormatter::formatSen($reward).' sedia untuk dituntut sebelum '
            .self::day(ResellerRebateMilestone::claimDeadline($month)->subDay()).' di portal:';
    }

    private function send(Reseller $reseller, string $event, string $key, string $text): void
    {
        $configured = config('services.openwa.session_id') !== null && config('services.openwa.api_key') !== null;
        $message = $text."\n\n".rtrim((string) config('services.reseller_portal.url'), '/').'/rebate';

        WhatsAppGroup::query()->where('owner_type', WhatsAppGroup::OWNER_RESELLER)->where('owner_id', $reseller->id)->where('is_active', true)
            ->orderBy('id')->get()
            ->each(fn (WhatsAppGroup $group) => $this->outbox->enqueue(
                session: CustomerNotification::SESSION_BOT,
                event: $event,
                dedupeKey: "{$key}:{$group->id}",
                message: $message,
                chatId: $group->whatsapp_group_id,
                skipReason: $configured ? null : 'Bot WhatsApp session is not configured',
            ));
    }

    private static function day(CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date->format('Y-m-d'))->locale('ms')->translatedFormat('j F Y');
    }

    private static function month(CarbonInterface $date): string
    {
        return CarbonImmutable::parse($date->format('Y-m-d'))->locale('ms')->translatedFormat('F Y');
    }
}
