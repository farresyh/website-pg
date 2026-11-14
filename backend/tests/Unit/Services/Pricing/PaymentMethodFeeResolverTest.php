<?php

namespace Tests\Unit\Services\Pricing;

use App\Models\PaymentMethod;
use App\Services\Pricing\PaymentMethodFeeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodFeeResolverTest extends TestCase
{
    use RefreshDatabase;

    private function paymentMethod(array $overrides = []): PaymentMethod
    {
        return PaymentMethod::query()->create(array_merge([
            'channel_code' => 'CARDS',
            'label' => 'Card',
            'category' => 'card',
            'gateway' => 'chip',
            'is_active' => true,
            'percentage_rate' => 1.9,
            'flat_fee_sen' => 90,
        ], $overrides));
    }

    public function test_resolves_a_percentage_plus_flat_channel(): void
    {
        $this->paymentMethod();
        $resolver = new PaymentMethodFeeResolver;

        $fee = $resolver->resolve('CARDS');

        $this->assertSame(1.9, $fee->percentageRate);
        $this->assertSame(90, $fee->flatFeeSen);
    }

    public function test_resolves_a_flat_only_channel(): void
    {
        $this->paymentMethod([
            'channel_code' => 'AMBANK_FPX',
            'label' => 'AmBank',
            'category' => 'fpx',
            'percentage_rate' => 0.0,
            'flat_fee_sen' => 210,
        ]);
        $resolver = new PaymentMethodFeeResolver;

        $fee = $resolver->resolve('AMBANK_FPX');

        $this->assertSame(0.0, $fee->percentageRate);
        $this->assertSame(210, $fee->flatFeeSen);
    }

    public function test_throws_for_an_unknown_channel(): void
    {
        $resolver = new PaymentMethodFeeResolver;

        $this->expectException(\RuntimeException::class);

        $resolver->resolve('BITCOIN');
    }

    public function test_throws_for_an_inactive_channel(): void
    {
        $this->paymentMethod(['channel_code' => 'GRABPAY', 'category' => 'ewallet', 'is_active' => false]);
        $resolver = new PaymentMethodFeeResolver;

        $this->expectException(\RuntimeException::class);

        $resolver->resolve('GRABPAY');
    }

    /** PRD §16 item 180: a pending payment still resolves its switched-off method; a new charge does not. */
    public function test_channel_lookup_finds_an_inactive_method_but_chargeable_lookup_does_not(): void
    {
        $this->paymentMethod();
        $this->paymentMethod(['channel_code' => 'GRABPAY', 'category' => 'ewallet', 'is_active' => false]);

        $this->assertSame('chip', PaymentMethod::forChannel('GRABPAY')->value('gateway'));
        $this->assertNull(PaymentMethod::chargeableForChannel('GRABPAY')->first());
        $this->assertSame('CARDS', PaymentMethod::chargeableForChannel('CARDS')->value('channel_code'));
    }

    /**
     * ADR-022 2026-11-14 addendum: a payment method is found by channel only
     * through PaymentMethod's scopes, and a started payment's gateway is read
     * from its own record, never looked up again.
     */
    public function test_no_code_looks_up_a_payment_method_or_its_gateway_outside_the_seam(): void
    {
        $model = realpath(app_path('Models/PaymentMethod.php'));
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getRealPath() === $model) {
                continue;
            }

            $source = file_get_contents($file->getRealPath());
            if (preg_match("/PaymentMethod::(query\(\))?[^;]*(where|firstWhere|whereIn)\(\s*'channel_code'/s", $source)
                || preg_match("/exists\(\s*'payment_methods'/", $source)
                || preg_match("/forChannel\([^;]*->value\(\s*'gateway'/s", $source)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getRealPath());
            }
        }

        $this->assertSame([], $offenders, 'Use PaymentMethod::forChannel()/chargeableForChannel(), ChargeablePaymentChannel, or the record\'s own gateway.');
    }
}
