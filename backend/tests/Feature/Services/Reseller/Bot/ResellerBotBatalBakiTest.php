<?php

namespace Tests\Feature\Services\Reseller\Bot;

use App\Models\Reseller;
use App\Models\ResellerBotCommandLog;
use App\Models\ResellerBotWalletTopup;
use App\Models\WalletTopupAttempt;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Reseller\Bot\ResellerBotReplyFormatter;
use App\Services\Reseller\Bot\ResellerBotService;
use App\Services\Reseller\ResellerWalletTopupService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\Support\ResellerBotFixtures;
use Tests\TestCase;

/**
 * ADR-135 decisions 4, 8 and 8a: a group-only reseller sees and controls a
 * pending top-up the same way a portal reseller does. `.batalbaki` goes
 * through the portal's own cancel (decision 5); this file pins what the
 * group is told for each outcome.
 */
class ResellerBotBatalBakiTest extends TestCase
{
    use RefreshDatabase;
    use ResellerBotFixtures;

    /** What CHIP's getPayment() says after the cancel: 'cancelled', 'paid', 'pending'. */
    public string $chipSays = 'cancelled';

    protected function setUp(): void
    {
        parent::setUp();
        // 12:00 MYT, so an attempt made now expires at 12:30.
        Carbon::setTestNow(Carbon::parse('2026-11-02 04:00:00', 'UTC'));
        $this->activeFpx();
        $this->captureReplies();
    }

    private function bindChip(): void
    {
        $test = $this;
        $this->app->bind('payment-gateway.chip', fn () => new class($test) implements PaymentGateway
        {
            public function __construct(private readonly ResellerBotBatalBakiTest $test) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                throw new RuntimeException('not used');
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return match ($this->test->chipSays) {
                    'paid' => PaymentResponse::success(['status' => 'paid'], status: PaymentStatus::Paid),
                    'pending' => PaymentResponse::success(['status' => 'pending_execute'], status: PaymentStatus::Pending),
                    default => PaymentResponse::success(['status' => 'cancelled', 'failure_is_final' => true], status: PaymentStatus::Failed),
                };
            }

            public function cancelPayment(string $paymentRequestId): PaymentResponse
            {
                return PaymentResponse::failure('400', 'not read');
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new RuntimeException('not used');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used');
            }
        });
    }

    private function pendingTopup(Reseller $reseller, bool $withLink = true): WalletTopupAttempt
    {
        $attempt = WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id,
            'reference' => 'WT-BATAL00001',
            'amount_sen' => 10000,
            'total_charged_sen' => 10100,
            'channel_code' => 'fpx',
            'status' => WalletTopupAttemptStatus::Pending->value,
            'chip_payment_ref' => $withLink ? 'chip-1' : null,
            'checkout_url' => $withLink ? 'https://gate.chip-in.asia/p/chip-1/' : null,
            'expires_at' => now()->addMinutes(30),
        ]);
        ResellerBotWalletTopup::query()->create([
            'wallet_topup_attempt_id' => $attempt->id,
            'reseller_id' => $reseller->id,
            'whatsapp_group_id' => self::GROUP_ID,
        ]);

        return $attempt;
    }

    private function send(string $text): void
    {
        app(ResellerBotService::class)->handle(self::GROUP_ID, $text, 'msg-'.uniqid(), now()->getTimestamp());
    }

    // --- .batalbaki, one reply per outcome ---

    public function test_batalbaki_cancels_the_pending_top_up_and_sends_only_its_own_reply(): void
    {
        $this->bindChip();
        $attempt = $this->pendingTopup($this->makeLinkedReseller());

        $this->send('.batalbaki');

        $this->assertSame(WalletTopupAttemptStatus::Failed, $attempt->fresh()->status);
        // decision 8a: a `.batalbaki` gets no ended push on top of its reply.
        $this->assertSame([
            'Tambah baki RM100.00 (Ref WT-BATAL00001) telah dibatalkan. Link lama tidak boleh dibayar lagi. '
            .'Anda boleh buat .topupbaki yang baharu.',
        ], $this->replies());
    }

    public function test_batalbaki_on_a_paid_top_up_leaves_it_alone(): void
    {
        $this->bindChip();
        $this->chipSays = 'paid';
        $attempt = $this->pendingTopup($this->makeLinkedReseller());

        $this->send('.batalbaki');

        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->fresh()->status);
        $this->assertSame([
            'Tambah baki RM100.00 (Ref WT-BATAL00001) sudah dibayar, jadi tidak boleh dibatalkan. '
            .'Baki anda akan dikemas kini sebentar lagi.',
        ], $this->replies());
    }

    public function test_batalbaki_while_the_bank_is_processing_is_refused_and_logged(): void
    {
        $this->bindChip();
        $this->chipSays = 'pending';
        $reseller = $this->makeLinkedReseller();
        $attempt = $this->pendingTopup($reseller);

        $this->send('.batalbaki');

        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->fresh()->status);
        $this->assertDatabaseHas('reseller_bot_command_logs', ['reseller_id' => $reseller->id, 'failure_reason' => 'topup_cancel_in_flight']);
        $this->assertSame([
            // ADR-076 2026-11-12 addendum, decision 4: when an unpaid one ends.
            'Tambah baki RM100.00 (Ref WT-BATAL00001) sedang diproses oleh bank, jadi belum boleh dibatalkan. '
            .'Jika tidak dibayar, ia tamat sendiri selepas '.ResellerWalletTopupService::expiryLabel($attempt).'. '
            .'Jika anda sudah bayar, baki akan masuk sendiri. Semak dengan .baki.',
        ], $this->replies());
    }

    public function test_batalbaki_with_nothing_pending_says_so_and_logs(): void
    {
        $this->bindChip();
        $reseller = $this->makeLinkedReseller();

        $this->send('.batalbaki');

        $this->assertDatabaseHas('reseller_bot_command_logs', ['reseller_id' => $reseller->id, 'failure_reason' => 'topup_cancel_none']);
        $this->assertSame(['Tiada tambah baki yang belum dibayar.'], $this->replies());
    }

    public function test_batalbaki_on_a_top_up_still_being_set_up_says_when_it_ends(): void
    {
        $this->bindChip();
        $attempt = $this->pendingTopup($this->makeLinkedReseller(), withLink: false);

        $this->send('.batalbaki');

        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->fresh()->status);
        $this->assertSame([
            'Tambah baki RM100.00 (Ref WT-BATAL00001) sedang disediakan dan akan tamat sendiri pada 12:30.',
        ], $this->replies());
    }

    /** Decision 8: cancelling only reduces exposure, so a deactivated account may do it. */
    public function test_a_deactivated_reseller_can_still_cancel(): void
    {
        $this->bindChip();
        $reseller = $this->makeLinkedReseller();
        $attempt = $this->pendingTopup($reseller);
        $reseller->update(['is_active' => false]);

        $this->send('.batalbaki');

        $this->assertSame(WalletTopupAttemptStatus::Failed, $attempt->fresh()->status);
    }

    public function test_batalbaki_is_rate_limited_at_5_per_minute(): void
    {
        $this->bindChip();
        $reseller = $this->makeLinkedReseller();
        RateLimiter::clear("reseller-bot-batalbaki:{$reseller->id}");

        for ($i = 0; $i < 6; $i++) {
            $this->send('.batalbaki');
        }

        $this->assertSame(5, ResellerBotCommandLog::query()->where('failure_reason', 'topup_cancel_none')->count());
        $this->assertContains('Terlalu banyak permintaan batal baki. Sila cuba sebentar lagi.', $this->replies());
    }

    // --- the other replies a group-only reseller relies on ---

    public function test_topupbaki_reply_says_when_the_link_ends_and_how_to_cancel(): void
    {
        $this->bindGateway();
        $this->makeLinkedReseller();

        $this->send('.topupbaki 50');

        $reply = $this->replies()[0];
        $this->assertStringContainsString('Link tamat pada 12:30.', $reply);
        $this->assertStringContainsString('Nak batal? Taip .batalbaki', $reply);
    }

    public function test_topupbaki_while_one_is_pending_hands_back_that_one_with_its_end_time(): void
    {
        $this->bindGateway();
        $attempt = $this->pendingTopup($this->makeLinkedReseller());

        $this->send('.topupbaki 150');

        $reply = $this->replies()[0];
        $this->assertSame(1, WalletTopupAttempt::query()->count());
        $this->assertStringContainsString('Anda masih ada satu tambah baki yang belum dibayar (tamat 12:30).', $reply);
        $this->assertStringContainsString('RM100.00', $reply);
        $this->assertStringContainsString($attempt->reference, $reply);
        $this->assertStringContainsString($attempt->checkout_url, $reply);
        $this->assertStringContainsString('atau taip .batalbaki untuk batal dan buat yang baharu.', $reply);
    }

    public function test_topupbaki_while_one_without_a_link_is_pending_says_it_is_being_set_up(): void
    {
        $this->bindGateway();
        $this->pendingTopup($this->makeLinkedReseller(), withLink: false);

        $this->send('.topupbaki 150');

        $this->assertSame([
            'Tambah baki RM100.00 (Ref WT-BATAL00001) sedang disediakan dan akan tamat sendiri pada 12:30.',
        ], $this->replies());
    }

    public function test_baki_shows_a_pending_top_up(): void
    {
        $this->bindGateway();
        $reseller = $this->makeLinkedReseller(walletBalance: 2500);

        $this->send('.baki');
        $this->pendingTopup($reseller);
        $this->send('.baki');

        $this->assertSame([
            'Baki Pembelian anda: RM25.00',
            "Baki Pembelian anda: RM25.00\nTambah baki belum dibayar: RM100.00 (Ref WT-BATAL00001, tamat 12:30). Taip .batalbaki untuk batal.",
        ], $this->replies());
    }

    public function test_the_command_list_names_batalbaki(): void
    {
        $this->assertStringContainsString('.batalbaki — batal tambah baki yang belum dibayar', ResellerBotReplyFormatter::commandList());
    }
}
