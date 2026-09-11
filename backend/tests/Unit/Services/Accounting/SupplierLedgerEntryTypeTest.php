<?php

namespace Tests\Unit\Services\Accounting;

use App\Services\Accounting\SupplierLedgerEntryType;
use PHPUnit\Framework\TestCase;

class SupplierLedgerEntryTypeTest extends TestCase
{
    public function test_backing_values_match_the_literals_the_adr_names(): void
    {
        $this->assertSame('TOPUP', SupplierLedgerEntryType::Topup->value);
        $this->assertSame('ORDER_DRAWDOWN', SupplierLedgerEntryType::OrderDrawdown->value);
        $this->assertSame('REFUND', SupplierLedgerEntryType::Refund->value);
        $this->assertSame('MANUAL_ADJUSTMENT', SupplierLedgerEntryType::ManualAdjustment->value);
    }

    public function test_coerce_passes_an_enum_through_unchanged(): void
    {
        $this->assertSame(SupplierLedgerEntryType::Refund, SupplierLedgerEntryType::coerce(SupplierLedgerEntryType::Refund));
    }

    public function test_coerce_resolves_a_known_string(): void
    {
        $this->assertSame(SupplierLedgerEntryType::Topup, SupplierLedgerEntryType::coerce('TOPUP'));
    }

    public function test_coerce_throws_loudly_on_an_unknown_string(): void
    {
        $this->expectException(\ValueError::class);
        SupplierLedgerEntryType::coerce('topup');
    }
}
