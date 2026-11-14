<?php

namespace App\Services\Reseller\Bot;

use App\Models\ResellerBotWalletTopup;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Notification\WhatsAppOutbox;
use App\Services\Reseller\WalletTopupEndCause;
use Illuminate\Support\Facades\DB;

/**
 * ADR-076 PR-H decision 2 — message 2 of the Bot channel's `.topupbaki`
 * flow. Unlike the order path (ADR-076 decision 5), a paid wallet
 * top-up fires *no* event whatsoever — `ResellerWalletService::completeTopup()`
 * just credits the ledger and marks the attempt `paid`. So this notifier
 * is called directly from inside `completeTopup()`, which is the one
 * seam both webhook (`ChipWebhookController`) and backstop
 * (`ReconcilePendingWalletTopupsCommand`) top-up-completion paths share.
 *
 * A `ResellerBotWalletTopup` row existing for the attempt is what scopes
 * this to the Bot channel specifically — a portal/self-serve-web top-up
 * has no WhatsApp group and correctly never reaches a reply here.
 * `notified_at` guards against a second send on a CHIP webhook
 * redelivery (belt-and-braces alongside `completeTopup()`'s own
 * already-`paid` early return).
 */
final class ResellerBotWalletTopupNotifier
{
    public function __construct(
        private readonly WhatsAppOutbox $outbox,
        private readonly LedgerService $ledger,
    ) {}

    public function notifyPaid(WalletTopupAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt) {
            /** @var ResellerBotWalletTopup|null $row */
            $row = ResellerBotWalletTopup::query()
                ->where('wallet_topup_attempt_id', $attempt->id)
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->notified_at !== null) {
                return;
            }

            $balanceSen = $this->ledger->balance(LedgerOwnerType::ResellerWallet, $attempt->reseller_id);

            $this->outbox->reply(
                WhatsAppOutbox::EVENT_BOT_TOPUP,
                "bot_topup:{$attempt->id}:paid",
                $row->whatsapp_group_id,
                ResellerBotReplyFormatter::topupPaid($balanceSen),
            );

            $row->update(['notified_at' => now()]);
        });
    }

    /**
     * ADR-135 decisions 8a/8b: a `.topupbaki` top-up ended unpaid. Called
     * only by `ResellerWalletService::markEnded()` after it won the move out
     * of `pending`; `ended_notified_at` guards a second send. A `.batalbaki`
     * cancel sends nothing here (its own reply says it), and a payment that
     * never got a link was never shown to the group.
     */
    public function notifyEnded(WalletTopupAttempt $attempt, WalletTopupEndCause $cause): void
    {
        $text = match ($cause) {
            WalletTopupEndCause::PortalCancel => ResellerBotReplyFormatter::topupCancelledViaPortal($attempt),
            WalletTopupEndCause::BankFailed, WalletTopupEndCause::Expired => ResellerBotReplyFormatter::topupEnded($attempt, $cause),
            WalletTopupEndCause::BotCancel, WalletTopupEndCause::CheckoutFailed => null,
        };

        if ($text === null) {
            return;
        }

        DB::transaction(function () use ($attempt, $text) {
            /** @var ResellerBotWalletTopup|null $row */
            $row = ResellerBotWalletTopup::query()
                ->where('wallet_topup_attempt_id', $attempt->id)
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->ended_notified_at !== null) {
                return;
            }

            $this->outbox->reply(WhatsAppOutbox::EVENT_BOT_TOPUP, "bot_topup:{$attempt->id}:ended", $row->whatsapp_group_id, $text);

            $row->update(['ended_notified_at' => now()]);
        });
    }
}
