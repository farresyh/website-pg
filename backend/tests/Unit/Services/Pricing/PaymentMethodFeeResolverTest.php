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
            'gateway' => 'xendit',
            'is_active' => true,
            'percentage_rate' => 1.9,
            'flat_fee_sen' => 90,
        ], $overrides));
    }

    public function test_resolves_a_percentage_plus_flat_channel(): void
    {
        $this->paymentMethod();
        $resolver = new PaymentMethodFeeResolver();

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
        $resolver = new PaymentMethodFeeResolver();

        $fee = $resolver->resolve('AMBANK_FPX');

        $this->assertSame(0.0, $fee->percentageRate);
        $this->assertSame(210, $fee->flatFeeSen);
    }

    public function test_throws_for_an_unknown_channel(): void
    {
        $resolver = new PaymentMethodFeeResolver();

        $this->expectException(\RuntimeException::class);

        $resolver->resolve('BITCOIN');
    }

    public function test_throws_for_an_inactive_channel(): void
    {
        $this->paymentMethod(['channel_code' => 'GRABPAY', 'category' => 'ewallet', 'is_active' => false]);
        $resolver = new PaymentMethodFeeResolver();

        $this->expectException(\RuntimeException::class);

        $resolver->resolve('GRABPAY');
    }
}
