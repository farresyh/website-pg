<?php

namespace Tests\Feature\Services\Reseller\Bot;

use App\Models\Reseller;
use App\Models\ResellerBotWalletTopup;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\Bot\ResellerBotWalletTopupNotifier;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-076 PR-H decision 2 — message 2 of the `.topupbaki` flow.
 * `OpenWaClient::sendText()` no-ops in tests (unconfigured), so every
 * assertion here is on the `reseller_bot_wallet_topups.notified_at`
 * guard state, not the reply text.
 */
class ResellerBotWalletTopupNotifierTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => 'Wallet Reseller', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }

    private function pendingAttempt(Reseller $reseller): WalletTopupAttempt
    {
        return WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id,
            'reference' => 'WT-'.strtoupper(str()->random(10)),
            'amount_sen' => 5000,
            'total_charged_sen' => 5100,
            'channel_code' => 'fpx',
            'status' => WalletTopupAttemptStatus::Pending->value,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    public function test_completing_a_bot_originated_topup_marks_the_row_notified(): void
    {
        $reseller = $this->reseller();
        $attempt = $this->pendingAttempt($reseller);
        ResellerBotWalletTopup::query()->create([
            'reseller_id' => $reseller->id,
            'wallet_topup_attempt_id' => $attempt->id,
            'whatsapp_group_id' => 'g1@g.us',
        ]);

        app(ResellerWalletService::class)->completeTopup($attempt);

        $this->assertNotNull(ResellerBotWalletTopup::query()->firstOrFail()->notified_at);
        $this->assertSame(5000, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
    }

    public function test_completing_a_non_bot_topup_is_a_clean_no_op(): void
    {
        $reseller = $this->reseller();
        $attempt = $this->pendingAttempt($reseller);

        app(ResellerWalletService::class)->completeTopup($attempt);

        $this->assertSame(0, ResellerBotWalletTopup::query()->count());
        $this->assertSame(WalletTopupAttemptStatus::Paid, $attempt->fresh()->status);
    }

    public function test_notify_paid_does_not_send_twice(): void
    {
        $reseller = $this->reseller();
        $attempt = $this->pendingAttempt($reseller);
        $row = ResellerBotWalletTopup::query()->create([
            'reseller_id' => $reseller->id,
            'wallet_topup_attempt_id' => $attempt->id,
            'whatsapp_group_id' => 'g1@g.us',
        ]);

        $notifier = app(ResellerBotWalletTopupNotifier::class);
        $notifier->notifyPaid($attempt);
        $firstNotifiedAt = $row->fresh()->notified_at;

        $notifier->notifyPaid($attempt);

        $this->assertEquals($firstNotifiedAt, $row->fresh()->notified_at);
    }
}
