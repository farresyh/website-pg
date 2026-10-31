<?php

namespace Tests\Feature\Services\Reseller;

use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\Reseller\InvalidWalletTopupAmountException;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\ResellerWalletTopupService;
use App\Services\Reseller\WalletBalanceCapExceededException;
use App\Services\Reseller\WalletTopupAttemptStatus;
use App\Services\Reseller\WalletTopupOverMaximumException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-131 decisions 4-7: RM10,000 per top-up and a RM100,000 default
 * balance cap (per-reseller override), enforced at the one seam both
 * the CHIP top-up and the admin manual credit pass through; a payment
 * already received is never refused.
 */
class PurchaseBalanceLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(?int $capSen = null): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => 'Limits Reseller', 'is_active' => true, 'balance_cap_sen' => $capSen, 'reseller_tier_id' => ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => 5, 'is_active' => true, 'sort_order' => 1])->id]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }

    private function holding(Reseller $reseller, int $sen): void
    {
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, $sen, 'wallet_topup');
    }

    private function bindGateway(): void
    {
        PaymentMethod::query()->create([
            'channel_code' => 'fpx', 'label' => 'FPX', 'category' => 'fpx', 'gateway' => 'chip',
            'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100,
        ]);

        $this->app->bind('payment-gateway.chip', fn () => new class implements PaymentGateway
        {
            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return PaymentResponse::success([
                    'payment_request_id' => 'pr-'.$request->referenceId,
                    'actions' => [['type' => 'REDIRECT', 'value' => 'https://gate.chip-in.asia/p/'.$request->referenceId]],
                ]);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return PaymentResponse::success(['payment_request_id' => $paymentRequestId], status: null);
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

    private function topups(): ResellerWalletTopupService
    {
        return app(ResellerWalletTopupService::class);
    }

    public function test_a_top_up_above_the_per_top_up_maximum_is_refused_before_any_attempt_exists(): void
    {
        $this->bindGateway();
        $reseller = $this->reseller();

        try {
            $this->topups()->initiate($reseller, 1_000_001, 'fpx', []);
            $this->fail('expected the over-maximum exception');
        } catch (WalletTopupOverMaximumException $e) {
            $this->assertSame(1_000_000, $e->maxSen);
            $this->assertInstanceOf(InvalidWalletTopupAmountException::class, $e);
        }

        $this->assertSame(0, WalletTopupAttempt::query()->count());
    }

    public function test_a_top_up_of_exactly_the_maximum_is_accepted(): void
    {
        $this->bindGateway();

        $attempt = $this->topups()->initiate($this->reseller(), 1_000_000, 'fpx', []);

        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->status);
    }

    public function test_a_top_up_that_would_take_the_balance_over_the_default_cap_is_refused(): void
    {
        $this->bindGateway();
        $reseller = $this->reseller();
        $this->holding($reseller, 9_500_000); // RM95,000

        try {
            $this->topups()->initiate($reseller, 500_100, 'fpx', []); // RM5,001
            $this->fail('expected the cap exception');
        } catch (WalletBalanceCapExceededException $e) {
            $this->assertSame(10_000_000, $e->capSen);
            $this->assertSame(500_000, $e->headroomSen);
        }

        $this->assertSame(0, WalletTopupAttempt::query()->count());
    }

    public function test_a_top_up_that_lands_exactly_on_the_cap_is_accepted(): void
    {
        $this->bindGateway();
        $reseller = $this->reseller();
        $this->holding($reseller, 9_500_000);

        $attempt = $this->topups()->initiate($reseller, 500_000, 'fpx', []);

        $this->assertSame(500_000, $attempt->amount_sen);
    }

    public function test_the_cap_compares_the_amount_credited_not_the_fee_inclusive_charge(): void
    {
        $this->bindGateway();
        $reseller = $this->reseller();
        $this->holding($reseller, 9_999_000); // RM99,990 held: exactly RM10.00 of headroom

        // RM10.00 credited fits exactly; the RM1 flat fee is charged on top and must not count.
        $attempt = $this->topups()->initiate($reseller, 1_000, 'fpx', []);

        $this->assertSame(1_100, $attempt->total_charged_sen);
    }

    public function test_a_per_reseller_cap_overrides_the_platform_default(): void
    {
        $this->bindGateway();
        $small = $this->reseller(capSen: 200_000); // RM2,000
        $this->holding($small, 150_000);

        $this->expectException(WalletBalanceCapExceededException::class);
        $this->topups()->initiate($small, 60_000, 'fpx', []);
    }

    public function test_a_higher_override_lets_a_large_reseller_hold_more_than_the_default(): void
    {
        $this->bindGateway();
        $big = $this->reseller(capSen: 50_000_000); // RM500,000
        $this->holding($big, 20_000_000);

        $attempt = $this->topups()->initiate($big, 1_000_000, 'fpx', []);

        $this->assertSame(1_000_000, $attempt->amount_sen);
    }

    public function test_the_limits_follow_the_platform_settings(): void
    {
        $this->bindGateway();
        PlatformSettings::current()->update(['topup_max_sen' => 500_000, 'balance_cap_default_sen' => 600_000]);

        $this->expectException(WalletTopupOverMaximumException::class);
        $this->topups()->initiate($this->reseller(), 500_001, 'fpx', []);
    }

    public function test_limits_report_the_effective_cap_and_the_headroom(): void
    {
        $reseller = $this->reseller();
        $this->holding($reseller, 2_500_000);

        $this->assertSame([
            'min_topup_sen' => 1_000,
            'max_topup_sen' => 1_000_000,
            'balance_cap_sen' => 10_000_000,
            'headroom_sen' => 7_500_000,
        ], app(ResellerWalletService::class)->limits($reseller));
    }

    public function test_headroom_is_zero_when_the_balance_is_already_above_the_cap(): void
    {
        $reseller = $this->reseller(capSen: 100_000);
        $this->holding($reseller, 300_000);

        $this->assertSame(0, app(ResellerWalletService::class)->limits($reseller)['headroom_sen']);
    }

    public function test_a_manual_credit_over_the_per_top_up_maximum_is_refused_and_writes_nothing(): void
    {
        $reseller = $this->reseller();

        try {
            app(ResellerWalletService::class)->manualCredit($reseller, 1_000_001, null, null, 1, 'key-over-max');
            $this->fail('expected the over-maximum exception');
        } catch (WalletTopupOverMaximumException) {
        }

        $this->assertSame(0, LedgerEntry::query()->where('type', 'wallet_topup')->count());
    }

    public function test_a_manual_credit_over_the_balance_cap_is_refused(): void
    {
        $reseller = $this->reseller(capSen: 300_000);
        $this->holding($reseller, 250_000);

        $this->expectException(WalletBalanceCapExceededException::class);
        app(ResellerWalletService::class)->manualCredit($reseller, 60_000, null, null, 1, 'key-over-cap');
    }

    public function test_a_manual_credit_within_the_limits_is_written(): void
    {
        $reseller = $this->reseller();

        $entry = app(ResellerWalletService::class)->manualCredit($reseller, 1_000_000, 'bank transfer', null, 1, 'key-ok');

        $this->assertSame(1_000_000, $entry->amount);
    }

    public function test_a_replayed_manual_credit_key_returns_the_first_entry_even_if_the_cap_has_since_filled(): void
    {
        $reseller = $this->reseller(capSen: 300_000);
        $first = app(ResellerWalletService::class)->manualCredit($reseller, 200_000, null, null, 1, 'key-replay');
        $this->holding($reseller, 100_000); // now exactly at the cap

        $again = app(ResellerWalletService::class)->manualCredit($reseller, 200_000, null, null, 1, 'key-replay');

        $this->assertSame($first->id, $again->id);
    }

    public function test_a_paid_top_up_is_credited_even_when_the_balance_has_since_passed_the_cap(): void
    {
        $this->bindGateway();
        $reseller = $this->reseller(capSen: 500_000);
        $attempt = $this->topups()->initiate($reseller, 400_000, 'fpx', []);
        $this->holding($reseller, 300_000); // an admin credit / refund lands while the payment is in flight

        app(ResellerWalletService::class)->completeTopup($attempt);

        $this->assertSame(700_000, app(ResellerWalletService::class)->balance($reseller)); // over the 500,000 cap, by design
        $this->assertSame(WalletTopupAttemptStatus::Paid, $attempt->fresh()->status);
    }
}
