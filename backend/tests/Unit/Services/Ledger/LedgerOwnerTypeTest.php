<?php

namespace Tests\Unit\Services\Ledger;

use App\Services\Ledger\LedgerOwnerType;
use PHPUnit\Framework\TestCase;

class LedgerOwnerTypeTest extends TestCase
{
    public function test_backing_values_match_the_literals_persisted_since_adr_002(): void
    {
        $this->assertSame('platform', LedgerOwnerType::Platform->value);
        $this->assertSame('reseller', LedgerOwnerType::Reseller->value);
    }

    public function test_coerce_passes_an_enum_through_unchanged(): void
    {
        $this->assertSame(LedgerOwnerType::Reseller, LedgerOwnerType::coerce(LedgerOwnerType::Reseller));
    }

    public function test_coerce_resolves_a_known_string(): void
    {
        $this->assertSame(LedgerOwnerType::Platform, LedgerOwnerType::coerce('platform'));
    }

    public function test_coerce_throws_loudly_on_an_unknown_string(): void
    {
        $this->expectException(\ValueError::class);
        LedgerOwnerType::coerce('customer');
    }
}
