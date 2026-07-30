<?php

namespace Tests\Feature\Database\Seeders;

use App\Models\PaymentMethod;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_all_channels_inactive_by_default_on_the_xendit_gateway(): void
    {
        (new PaymentMethodSeeder())->run();

        $this->assertGreaterThan(40, PaymentMethod::query()->count());
        $this->assertSame(0, PaymentMethod::query()->where('is_active', true)->count());
        $this->assertSame(
            PaymentMethod::query()->count(),
            PaymentMethod::query()->where('gateway', 'xendit')->count(),
        );
        $this->assertTrue(PaymentMethod::query()->where('channel_code', 'AMBANK_FPX')->exists());
        $this->assertTrue(PaymentMethod::query()->where('channel_code', 'GRABPAY')->exists());
        $this->assertTrue(PaymentMethod::query()->where('channel_code', 'CARDS')->exists());
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

    public function test_running_twice_does_not_duplicate_rows(): void
    {
        (new PaymentMethodSeeder())->run();
        $countAfterFirstRun = PaymentMethod::query()->count();

        (new PaymentMethodSeeder())->run();

        $this->assertSame($countAfterFirstRun, PaymentMethod::query()->count());
    }
}
