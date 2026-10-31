<?php

namespace Tests\Feature\Services\Reseller\Bot;

use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\ResellerBotCommandLog;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\Bot\ResellerBotReplyFormatter;
use App\Services\Reseller\Bot\ResellerBotService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ResellerBotFixtures;
use Tests\TestCase;

/**
 * ADR-075 / PR-F build addendum — same money/catalog seams the Reseller
 * API (PR-E) already tests, over the WhatsApp command surface instead of
 * HTTP. `OpenWaClient::sendText()` is unconfigured in tests (no
 * session_id/api_key), so it no-ops rather than making a real HTTP
 * call — every assertion here goes through the DB/ledger, never a
 * mocked reply.
 *
 * This file: the .topupbaki wallet top-up command.
 */
class ResellerBotTopupTest extends TestCase
{
    use RefreshDatabase;
    use ResellerBotFixtures;

    // --- ADR-076 PR-H: .topupbaki ---

    public function test_topupbaki_creates_a_pending_attempt_and_a_bot_topup_row(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 50', 'msg-1');

        $attempt = WalletTopupAttempt::query()->firstOrFail();
        $this->assertSame(5000, $attempt->amount_sen);
        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->status);
        $this->assertDatabaseHas('reseller_bot_wallet_topups', [
            'wallet_topup_attempt_id' => $attempt->id,
            'reseller_id' => $reseller->id,
            'whatsapp_group_id' => self::GROUP_ID,
            'notified_at' => null,
        ]);
    }

    /** ADR-133 decision 20: the same refusal as the portal, through the shared seam. */
    public function test_topupbaki_from_a_reseller_with_no_tier_is_refused_without_an_attempt(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $this->captureReplies();
        $reseller = $this->makeLinkedReseller();
        $reseller->update(['reseller_tier_id' => null]);

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 50', 'msg-1');

        $this->assertSame(0, WalletTopupAttempt::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'topup_no_tier_assigned',
        ]);
        $this->assertContains(
            'Tambah Baki Pembelian tidak dapat dibuat: akaun anda belum ditetapkan tier harga. Sila hubungi admin.',
            $this->replies(),
        );
    }

    public function test_topupbaki_below_the_minimum_is_rejected_without_a_gateway_call(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 5', 'msg-1');

        $this->assertSame(0, WalletTopupAttempt::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'topup_below_minimum',
        ]);
    }

    public function test_topupbaki_above_the_per_top_up_maximum_gets_its_own_reply_and_no_attempt(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 15000', 'msg-1');

        $this->assertSame(0, WalletTopupAttempt::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'topup_over_maximum',
        ]);
        $this->assertSame(
            'Jumlah maksimum untuk sekali tambah baki ialah RM10,000.00.',
            ResellerBotReplyFormatter::topupOverMaximum(1_000_000),
        );
    }

    public function test_topupbaki_that_would_pass_the_balance_cap_gets_its_own_reply_and_no_attempt(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $reseller = $this->makeLinkedReseller();
        $reseller->update(['balance_cap_sen' => 300_000]);
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 250_000, 'wallet_topup');

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 600', 'msg-1');

        $this->assertSame(0, WalletTopupAttempt::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'topup_over_balance_cap',
        ]);
        $this->assertSame(
            'Ini akan melebihi had Baki Pembelian anda (RM3,000.00). Anda boleh tambah sehingga RM500.00 sekarang.',
            ResellerBotReplyFormatter::topupOverCap(300_000, 50_000),
        );
    }

    public function test_the_bot_never_calls_the_balance_a_wallet_to_the_reseller(): void
    {
        $replies = [
            ResellerBotReplyFormatter::commandList(),
            ResellerBotReplyFormatter::topupPaid(12_345),
            ResellerBotReplyFormatter::topupBelowMinimum(),
            ResellerBotReplyFormatter::topupUnavailable(),
            ResellerBotReplyFormatter::topupCheckoutFailed(),
        ];

        foreach ($replies as $reply) {
            $this->assertStringNotContainsStringIgnoringCase('wallet', $reply);
            $this->assertStringNotContainsStringIgnoringCase('top-up', $reply);
        }
    }

    public function test_topupbaki_with_a_non_numeric_amount_is_rejected(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki lima', 'msg-1');

        $this->assertSame(0, WalletTopupAttempt::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'topup_invalid_amount',
        ]);
    }

    public function test_topupbaki_hands_back_the_existing_link_when_one_is_already_pending(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 50', 'msg-1');
        // A second request while the first is still pending — decision 3:
        // reply with the existing attempt's link, no new attempt created.
        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 80', 'msg-2');

        $this->assertSame(1, WalletTopupAttempt::query()->count());
    }

    /**
     * E9 hardening (2026-09-10 reseller-family audit, `docs/build-log.md`):
     * `.topupbaki`'s default-channel pick ("first active method by
     * category, label") has no other test pinning it — a future admin
     * reorder or a new payment method could silently change which
     * channel a reseller gets top-up'd through. Pins today's expected
     * pick (`fpx` sorts before `wallet_qr` alphabetically) so a future
     * ordering change fails this test loudly instead of drifting silent.
     */
    public function test_topupbaki_picks_the_first_active_method_by_category_then_label(): void
    {
        $this->activeFpx();
        PaymentMethod::query()->create([
            'channel_code' => 'duitnow_qr', 'label' => 'DuitNow QR', 'category' => 'wallet_qr',
            'gateway' => 'chip', 'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 0,
        ]);
        $this->bindGateway();
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 50', 'msg-1');

        $this->assertSame('fpx', WalletTopupAttempt::query()->firstOrFail()->channel_code);
    }

    public function test_topupbaki_is_unavailable_when_no_payment_method_is_active(): void
    {
        $this->bindGateway();
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 50', 'msg-1');

        $this->assertSame(0, WalletTopupAttempt::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'topup_no_active_payment_method',
        ]);
    }

    public function test_topupbaki_is_rate_limited_at_5_per_minute(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $reseller = $this->makeLinkedReseller();
        RateLimiter::clear("reseller-bot-topupbaki:{$reseller->id}");

        // 6 calls, decision 4's limit is 5/min. Each successful call would
        // otherwise reuse the same pending attempt (decision 3), so the
        // rate-limit assertion keys off the command log instead: the 6th
        // must be blocked before it reaches the handler.
        for ($i = 0; $i < 6; $i++) {
            app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 5', "msg-{$i}");
        }

        // 5 got through to the handler (each logged 'topup_below_minimum'),
        // the 6th was rate-limited before the handler ran.
        $this->assertSame(5, ResellerBotCommandLog::query()
            ->where('reseller_id', $reseller->id)
            ->where('failure_reason', 'topup_below_minimum')
            ->count());
    }
}
