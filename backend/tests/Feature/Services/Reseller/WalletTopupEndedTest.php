<?php

namespace Tests\Feature\Services\Reseller;

use App\Models\CustomerNotification;
use App\Models\Reseller;
use App\Models\ResellerBotWalletTopup;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use App\Services\Reseller\WalletTopupEndCause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADR-135 decisions 6, 8a, 8b: one seam ends a pending top-up. The cause
 * names the status (expiry → Expired, anything else → Failed) and, for a
 * top-up started in a WhatsApp group, the one message the group gets.
 */
class WalletTopupEndedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openwa.session_id' => 'session-1', 'services.openwa.api_key' => 'key-1']);
        Queue::fake();
    }

    private function attempt(bool $fromBot = true, string $status = 'pending'): WalletTopupAttempt
    {
        $reseller = Reseller::query()->create(['business_name' => 'Ended Reseller', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        $attempt = WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id,
            'reference' => 'WT-ENDED00001',
            'amount_sen' => 10000,
            'total_charged_sen' => 10100,
            'channel_code' => 'fpx',
            'gateway' => 'chip',
            'status' => $status,
            'chip_payment_ref' => 'chip-ended',
            'expires_at' => now()->addMinutes(30),
        ]);

        if ($fromBot) {
            ResellerBotWalletTopup::query()->create([
                'reseller_id' => $reseller->id,
                'wallet_topup_attempt_id' => $attempt->id,
                'whatsapp_group_id' => 'group-1@g.us',
            ]);
        }

        return $attempt;
    }

    private function end(WalletTopupAttempt $attempt, WalletTopupEndCause $cause): bool
    {
        return app(ResellerWalletService::class)->markEnded($attempt, $cause);
    }

    /** @return list<string> "group|text" of every bot top-up message (outbox rows, ADR-116 2026-11-14 addendum) */
    private function groupMessages(): array
    {
        return CustomerNotification::query()->where('event', 'bot_topup')->orderBy('id')->get()
            ->map(fn (CustomerNotification $n) => $n->chat_id.'|'.$n->message)->all();
    }

    public function test_a_bank_failure_fails_the_attempt_and_tells_the_group_once(): void
    {
        $attempt = $this->attempt();

        $this->assertTrue($this->end($attempt, WalletTopupEndCause::BankFailed));
        $this->assertFalse($this->end($attempt->fresh(), WalletTopupEndCause::BankFailed));

        $this->assertSame(WalletTopupAttemptStatus::Failed, $attempt->fresh()->status);
        $messages = $this->groupMessages();
        $this->assertCount(1, $messages);
        $this->assertStringStartsWith('group-1@g.us|', $messages[0]);
        $this->assertStringContainsString('RM100.00', $messages[0]);
        $this->assertStringContainsString('WT-ENDED00001', $messages[0]);
        $this->assertStringContainsString('tidak berjaya', $messages[0]);
        $this->assertStringContainsString('.topupbaki', $messages[0]);
        $this->assertNotNull(ResellerBotWalletTopup::query()->firstOrFail()->ended_notified_at);
    }

    public function test_an_expiry_names_the_attempt_expired(): void
    {
        $attempt = $this->attempt();

        $this->end($attempt, WalletTopupEndCause::Expired);

        $this->assertSame(WalletTopupAttemptStatus::Expired, $attempt->fresh()->status);
        $this->assertStringContainsString('tamat tempoh', $this->groupMessages()[0]);
    }

    public function test_a_portal_cancel_of_a_group_top_up_tells_the_group(): void
    {
        $attempt = $this->attempt();

        $this->end($attempt, WalletTopupEndCause::PortalCancel);

        $this->assertSame(WalletTopupAttemptStatus::Failed, $attempt->fresh()->status);
        $this->assertStringContainsString('dibatalkan melalui portal', $this->groupMessages()[0]);
    }

    /** The `.batalbaki` reply already says it; no second message. */
    public function test_a_bot_cancel_sends_no_push(): void
    {
        $attempt = $this->attempt();

        $this->end($attempt, WalletTopupEndCause::BotCancel);

        $this->assertSame(WalletTopupAttemptStatus::Failed, $attempt->fresh()->status);
        $this->assertSame([], $this->groupMessages());
    }

    public function test_a_portal_top_up_ends_with_no_group_message(): void
    {
        $attempt = $this->attempt(fromBot: false);

        $this->end($attempt, WalletTopupEndCause::BankFailed);

        $this->assertSame(WalletTopupAttemptStatus::Failed, $attempt->fresh()->status);
        $this->assertSame([], $this->groupMessages());
    }

    /** A Paid that committed first wins: nothing changes and nobody is told it failed. */
    public function test_an_attempt_already_paid_is_never_ended(): void
    {
        $attempt = $this->attempt(status: WalletTopupAttemptStatus::Paid->value);

        $this->assertFalse($this->end($attempt, WalletTopupEndCause::BankFailed));

        $this->assertSame(WalletTopupAttemptStatus::Paid, $attempt->fresh()->status);
        $this->assertSame([], $this->groupMessages());
    }
}
