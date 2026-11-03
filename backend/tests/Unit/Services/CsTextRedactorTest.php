<?php

namespace Tests\Unit\Services;

use App\Services\CsAssistant\CsTextRedactor;
use PHPUnit\Framework\TestCase;

/** ADR-136 decision 33: one redactor for storage, model payloads and review. */
class CsTextRedactorTest extends TestCase
{
    public function test_it_masks_contact_details_ids_and_codes_but_keeps_the_question(): void
    {
        $text = 'Salam, order PG-ABCDEF123456 (ID 51049607 server 2005) tak masuk. Voucher VC-AB12CD34, '
            .'WA +60 19-876 5432 / 0198765432, email Aina.Z@gmail.com, bayar RM22.01';

        $masked = CsTextRedactor::redact($text);

        foreach (['PG-ABCDEF123456', '51049607', '0198765432', '876 5432', 'Aina.Z@gmail.com', 'VC-AB12CD34'] as $secret) {
            $this->assertStringNotContainsString($secret, $masked);
        }
        $this->assertStringContainsString('tak masuk', $masked);
        $this->assertStringContainsString('RM22.01', $masked);
        $this->assertStringContainsString('server 2005', $masked);
    }

    public function test_it_finds_order_numbers_uppercased_and_unique(): void
    {
        $this->assertSame(
            ['PG-ABCDEF123456', 'PG-ZZZZZZ999999'],
            CsTextRedactor::orderNumbers('pg-abcdef123456 and PG-ABCDEF123456, also PG-ZZZZZZ999999'),
        );
        $this->assertSame([], CsTextRedactor::orderNumbers('no order here'));
    }
}
