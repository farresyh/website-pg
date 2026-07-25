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

    public function test_running_twice_does_not_duplicate_rows(): void
    {
        (new PaymentMethodSeeder())->run();
        $countAfterFirstRun = PaymentMethod::query()->count();

        (new PaymentMethodSeeder())->run();

        $this->assertSame($countAfterFirstRun, PaymentMethod::query()->count());
    }
}
