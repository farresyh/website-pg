<?php

namespace Tests\Unit\Services\Pricing;

use App\Services\Pricing\PaymentMethodFeeResolver;
use Tests\TestCase;

class PaymentMethodFeeResolverTest extends TestCase
{
    public function test_resolves_a_percentage_plus_flat_method(): void
    {
        $resolver = new PaymentMethodFeeResolver();

        $fee = $resolver->resolve('card');

        $this->assertSame(1.9, $fee->percentageRate);
        $this->assertSame(90, $fee->flatFeeSen);
    }

    public function test_resolves_a_flat_only_method(): void
    {
        $resolver = new PaymentMethodFeeResolver();

        $fee = $resolver->resolve('fpx');

        $this->assertSame(0.0, $fee->percentageRate);
        $this->assertSame(210, $fee->flatFeeSen);
    }

    public function test_throws_for_an_unconfigured_payment_method(): void
    {
        $resolver = new PaymentMethodFeeResolver();

        $this->expectException(\RuntimeException::class);

        $resolver->resolve('bitcoin');
    }
}
