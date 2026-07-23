<?php

namespace Tests\Unit\Services\Order;

use App\Services\Order\OrderNumberService;
use PHPUnit\Framework\TestCase;

class OrderNumberServiceTest extends TestCase
{
    public function test_generates_an_order_number_with_the_expected_prefix(): void
    {
        $service = new OrderNumberService();

        $this->assertStringStartsWith('KRS-', $service->generate());
    }

    public function test_generates_unique_order_numbers_across_calls(): void
    {
        $service = new OrderNumberService();

        $this->assertNotSame($service->generate(), $service->generate());
    }
}
