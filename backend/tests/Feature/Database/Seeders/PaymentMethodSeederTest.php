<?php

namespace Tests\Feature\Database\Seeders;

use App\Models\PaymentMethod;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_all_channels_inactive_by_default(): void
    {
        (new PaymentMethodSeeder())->run();

        $this->assertGreaterThan(40, PaymentMethod::query()->count());
        $this->assertSame(0, PaymentMethod::query()->where('is_active', true)->count());
        $this->assertTrue(PaymentMethod::query()->where('channel_code', 'AMBANK_FPX')->exists());
        $this->assertTrue(PaymentMethod::query()->where('channel_code', 'GRABPAY')->exists());
        $this->assertTrue(PaymentMethod::query()->where('channel_code', 'CARDS')->exists());
    }

    /**
     * ADR-022 decision 5 / this ADR's newest addendum item 6 — CHIP's
     * FPX channel codes are CHIP's own real enum values ('fpx',
     * 'fpx_b2b1'), stored as-is (same pass-through convention Xendit's
     * own channel codes already use — no translation table). Flat fee
     * only, no percentage component (chip-in.asia/collect, confirmed
     * 2026-07-30 during the multi-gateway research): RM1 personal /
     * RM2 business. `method_key` is deliberately its own value, NOT
     * shared with any of the 39 Xendit FPX bank rows — CHIP's FPX is a
     * single generic redirect (customer picks their bank on CHIP's own
     * hosted page, confirmed against CHIP's real OpenAPI spec,
     * 2026-08-03), not a per-bank equivalent of any one Xendit row, so
     * the 1:1 method_key exclusivity this project built (task 2) does
     * not apply here — switching FPX from Xendit to CHIP is a
     * deliberate admin migration action (deactivate the Xendit rows,
     * activate these), not something the mutual-exclusivity guard
     * needs to enforce automatically.
     */
    public function test_seeds_chip_fpx_channels_inactive_with_their_own_method_keys(): void
    {
        (new PaymentMethodSeeder())->run();

        $personal = PaymentMethod::query()->where('channel_code', 'fpx')->firstOrFail();
        $this->assertSame('chip', $personal->gateway);
        $this->assertSame('fpx', $personal->category);
        $this->assertFalse($personal->is_active);
        $this->assertSame('fpx_chip', $personal->method_key);
        $this->assertSame(0.0, (float) $personal->percentage_rate);
        $this->assertSame(100, $personal->flat_fee_sen);

        $business = PaymentMethod::query()->where('channel_code', 'fpx_b2b1')->firstOrFail();
        $this->assertSame('chip', $business->gateway);
        $this->assertSame('fpx_chip_b2b1', $business->method_key);
        $this->assertSame(200, $business->flat_fee_sen);

        // method_key stays distinct from every Xendit FPX bank row —
        // no automatic conflict, per the doc comment above.
        $this->assertFalse(
            PaymentMethod::query()->where('method_key', $personal->method_key)->where('gateway', 'xendit')->exists(),
        );
    }

    /**
     * Locks in the real Xendit Malaysia rate card (xendit.co/en/pricing,
     * verified 2026-07-30) so these figures can't silently drift back
     * toward guessed placeholders — see PaymentMethodSeeder's own doc
     * comment for the known CARDS/virtual-account approximations this
     * doesn't (and can't) close.
     */
    public function test_seeds_fee_rates_matching_xendits_published_rate_card(): void
    {
        (new PaymentMethodSeeder())->run();

        $this->assertFeeRate('AMBANK_FPX', percentageRate: 0.0, flatFeeSen: 210);
        $this->assertFeeRate('AMBANK_FPX_BUSINESS', percentageRate: 0.0, flatFeeSen: 290);
        $this->assertFeeRate('GRABPAY', percentageRate: 2.00, flatFeeSen: 90);
        $this->assertFeeRate('TOUCHNGO', percentageRate: 1.80, flatFeeSen: 90);
        $this->assertFeeRate('SHOPEEPAY', percentageRate: 2.50, flatFeeSen: 90);
        $this->assertFeeRate('WECHATPAY', percentageRate: 2.50, flatFeeSen: 90);
        $this->assertFeeRate('CARDS', percentageRate: 1.90, flatFeeSen: 90);
        $this->assertFeeRate('AMBANK_VIRTUAL_ACCOUNT', percentageRate: 0.0, flatFeeSen: 0);
    }

    private function assertFeeRate(string $channelCode, float $percentageRate, int $flatFeeSen): void
    {
        $method = PaymentMethod::query()->where('channel_code', $channelCode)->firstOrFail();

        $this->assertSame($percentageRate, (float) $method->percentage_rate, "percentage_rate mismatch for {$channelCode}");
        $this->assertSame($flatFeeSen, $method->flat_fee_sen, "flat_fee_sen mismatch for {$channelCode}");
    }

    /**
     * ADR-022's newest addendum, decision 4 — every seeded row gets a
     * method_key so PaymentMethodController::updateStatus()'s
     * mutual-exclusivity guard has something to key on once a second
     * gateway's rows exist. Today's Xendit-only rows each get a
     * distinct key derived from their own channel_code (no two rows
     * represent the same real-world method yet).
     */
    public function test_seeds_a_distinct_method_key_for_every_channel(): void
    {
        (new PaymentMethodSeeder())->run();

        $method = PaymentMethod::query()->where('channel_code', 'AMBANK_FPX')->firstOrFail();
        $this->assertSame('ambank_fpx', $method->method_key);

        $this->assertSame(
            PaymentMethod::query()->count(),
            PaymentMethod::query()->distinct('method_key')->count('method_key'),
        );
    }

    public function test_running_twice_does_not_duplicate_rows(): void
    {
        (new PaymentMethodSeeder())->run();
        $countAfterFirstRun = PaymentMethod::query()->count();

        (new PaymentMethodSeeder())->run();

        $this->assertSame($countAfterFirstRun, PaymentMethod::query()->count());
    }
}
