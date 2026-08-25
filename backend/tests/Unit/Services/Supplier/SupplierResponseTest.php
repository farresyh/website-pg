<?php

namespace Tests\Unit\Services\Supplier;

use App\Services\Supplier\SupplierOutcome;
use App\Services\Supplier\SupplierResponse;
use PHPUnit\Framework\TestCase;

/**
 * ADR-032 decision 2: SupplierResponse's normalized outcome is the one
 * additive interface change the whole Digiflazz Pending-state plan
 * needs — fulfill() branches on ->outcome, never on ->success alone
 * (a business-level Pending is neither a clean success nor a failure).
 */
class SupplierResponseTest extends TestCase
{
    public function test_success_carries_the_success_outcome_and_a_true_success_flag(): void
    {
        $response = SupplierResponse::success(['supplier_ref' => 'GV-1']);

        $this->assertSame(SupplierOutcome::Success, $response->outcome);
        $this->assertTrue($response->success);
        $this->assertSame(['supplier_ref' => 'GV-1'], $response->data);
    }

    /**
     * Pending is deliberately NOT a "success" in the ->success boolean
     * sense (no delivery has actually happened yet) — existing callers
     * that only checked ->success (ProductSyncService, the circuit
     * breaker's isServerError check) must keep working unchanged.
     */
    public function test_pending_carries_the_pending_outcome_and_a_false_success_flag(): void
    {
        $response = SupplierResponse::pending(['trx_id' => 'DGFLZ-1']);

        $this->assertSame(SupplierOutcome::Pending, $response->outcome);
        $this->assertFalse($response->success);
        $this->assertSame(['trx_id' => 'DGFLZ-1'], $response->data);
        $this->assertNull($response->errorCode);
        $this->assertFalse($response->isServerError);
    }

    public function test_failure_carries_the_failure_outcome(): void
    {
        $response = SupplierResponse::failure('timeout', 'Supplier timed out');

        $this->assertSame(SupplierOutcome::Failure, $response->outcome);
        $this->assertFalse($response->success);
    }
}
