<?php

namespace Tests\Unit\Services\Payment\Chip;

use App\Services\Payment\Chip\FpxBankRedirect;
use Tests\TestCase;

class FpxBankRedirectTest extends TestCase
{
    private const URL = 'https://gate.chip-in.asia/p/abc/';

    private function actions(string $url = self::URL): array
    {
        return [['type' => 'REDIRECT', 'descriptor' => 'WEB_URL', 'value' => $url]];
    }

    /** The 20 B2C codes from CHIP's FPX doc table (docs.chip-in.asia/chip-collect/overview/direct-post/fpx), minus Maybank2E. */
    public function test_the_list_is_pinned_to_chips_documented_codes(): void
    {
        $expected = [
            'ABB0233', 'ABMB0212', 'AGRO01', 'AMBB0209', 'BIMB0340', 'BMMB0341', 'BKRM0602', 'BOCM01', 'BSN0601',
            'BCBB0235', 'HLB0224', 'HSBC0223', 'KFH0346', 'MB2U0227', 'MBSB001', 'OCBC0229', 'PBB0233', 'RHB0218',
            'SCB0216', 'UOB0226',
        ];

        $codes = FpxBankRedirect::codes();
        sort($expected);
        sort($codes);

        $this->assertSame($expected, $codes);
    }

    public function test_appends_chips_direct_post_parameters_to_the_web_url(): void
    {
        $result = FpxBankRedirect::apply($this->actions(), 'MB2U0227');

        $this->assertSame(self::URL.'?preferred=fpx&fpx_bank_code=MB2U0227', $result[0]['value']);
    }

    public function test_no_bank_or_an_unlisted_bank_leaves_the_url_untouched(): void
    {
        $this->assertSame($this->actions(), FpxBankRedirect::apply($this->actions(), null));
        $this->assertSame($this->actions(), FpxBankRedirect::apply($this->actions(), 'XXX9999'));
    }

    public function test_an_invoice_url_is_left_alone(): void
    {
        $invoice = $this->actions('https://gate.chip-in.asia/p/abc/invoice/');

        $this->assertSame($invoice, FpxBankRedirect::apply($invoice, 'MB2U0227'));
    }

    public function test_other_action_shapes_pass_through(): void
    {
        $flat = ['desktop_web_checkout_url' => self::URL];

        $this->assertSame($flat, FpxBankRedirect::apply($flat, 'MB2U0227'));
        $this->assertSame([], FpxBankRedirect::apply([], 'MB2U0227'));
    }
}
