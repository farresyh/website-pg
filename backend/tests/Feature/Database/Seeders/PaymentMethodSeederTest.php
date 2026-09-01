<?php

namespace Tests\Feature\Database\Seeders;

use App\Models\PaymentMethod;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ADR-022's 2026-09-01 addendum — CHIP-only. Every seeded row is a
     * CHIP channel and starts inactive: activation is a manual admin
     * action gated on a real `app:chip-smoke-test` pass (decision 5),
     * never seeded on.
     */
    public function test_seeds_only_chip_channels_all_inactive(): void
    {
        (new PaymentMethodSeeder)->run();

        $this->assertSame(3, PaymentMethod::query()->count());
        $this->assertSame(0, PaymentMethod::query()->where('is_active', true)->count());
        $this->assertSame(0, PaymentMethod::query()->where('gateway', '!=', 'chip')->count());
    }

    public function test_seeds_the_fpx_channels_with_their_flat_fees(): void
    {
        (new PaymentMethodSeeder)->run();

        $personal = PaymentMethod::query()->where('channel_code', 'fpx')->firstOrFail();
        $this->assertSame('chip', $personal->gateway);
        $this->assertSame('fpx', $personal->category);
        $this->assertSame('fpx_chip', $personal->method_key);
        $this->assertSame(0.0, (float) $personal->percentage_rate);
        $this->assertSame(100, $personal->flat_fee_sen);

        $business = PaymentMethod::query()->where('channel_code', 'fpx_b2b1')->firstOrFail();
        $this->assertSame('fpx_chip_b2b1', $business->method_key);
        $this->assertSame(0.0, (float) $business->percentage_rate);
        $this->assertSame(200, $business->flat_fee_sen);
    }

    public function test_seeds_the_duitnow_qr_launch_channel(): void
    {
        (new PaymentMethodSeeder)->run();

        $duitnow = PaymentMethod::query()->where('channel_code', 'duitnow_qr')->firstOrFail();
        $this->assertSame('chip', $duitnow->gateway);
        $this->assertSame('duitnow_qr_chip', $duitnow->method_key);
        $this->assertFalse($duitnow->is_active);
    }

    /**
     * ADR-022 2026-08-03 addendum decision 4 — every row carries a
     * distinct method_key so the mutual-exclusivity guard has something
     * to key on the day a second gateway's rows exist.
     */
    public function test_every_row_has_a_distinct_method_key(): void
    {
        (new PaymentMethodSeeder)->run();

        $this->assertSame(
            PaymentMethod::query()->count(),
            PaymentMethod::query()->distinct('method_key')->count('method_key'),
        );
    }

    public function test_running_twice_does_not_duplicate_rows(): void
    {
        (new PaymentMethodSeeder)->run();
        $countAfterFirstRun = PaymentMethod::query()->count();

        (new PaymentMethodSeeder)->run();

        $this->assertSame($countAfterFirstRun, PaymentMethod::query()->count());
    }
}
