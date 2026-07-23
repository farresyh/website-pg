<?php

namespace Tests\Unit\Services\Order;

use App\Services\Order\ReferenceNumberService;
use PHPUnit\Framework\TestCase;

class ReferenceNumberServiceTest extends TestCase
{
    public function test_generates_a_reference_number_with_the_expected_prefix(): void
    {
        $service = new ReferenceNumberService();

        $reference = $service->generate();

        $this->assertStringStartsWith('REF-', $reference);
    }

    /**
     * Two orders must never collide on their idempotency key (ORD-8).
     */
    public function test_generates_unique_reference_numbers_across_calls(): void
    {
        $service = new ReferenceNumberService();

        $first = $service->generate();
        $second = $service->generate();

        $this->assertNotSame($first, $second);
    }

    /**
     * ORD-8: the reference number is generated once, before the first
     * supplier call, and reused on every retry — never regenerated.
     * `resolve()` is what a retry code path calls: if the order already
     * has one, it comes back untouched.
     */
    public function test_resolve_returns_the_existing_reference_number_unchanged(): void
    {
        $service = new ReferenceNumberService();

        $result = $service->resolve('REF-01ARZ3NDEKTSV4RRFFQ69G5FAV');

        $this->assertSame('REF-01ARZ3NDEKTSV4RRFFQ69G5FAV', $result);
    }

    public function test_resolve_generates_a_new_reference_number_when_none_exists_yet(): void
    {
        $service = new ReferenceNumberService();

        $result = $service->resolve(null);

        $this->assertStringStartsWith('REF-', $result);
    }

    /**
     * An empty string is treated the same as "not set yet" — a blank
     * column value must not be reused as if it were a real reference.
     */
    public function test_resolve_treats_empty_string_as_no_existing_reference(): void
    {
        $service = new ReferenceNumberService();

        $result = $service->resolve('');

        $this->assertStringStartsWith('REF-', $result);
        $this->assertNotSame('', $result);
    }
}
