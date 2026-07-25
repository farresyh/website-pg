<?php

namespace Tests\Unit\Services\PlayerValidation;

use App\Services\PlayerValidation\CountryCodeDecoder;
use Tests\TestCase;

class CountryCodeDecoderTest extends TestCase
{
    public function test_decodes_a_flag_emoji_preceded_by_a_country_name(): void
    {
        $this->assertSame('MY', CountryCodeDecoder::fromFlagEmoji('Malaysia 🇲🇾'));
    }

    public function test_decodes_a_bare_flag_emoji(): void
    {
        $this->assertSame('MY', CountryCodeDecoder::fromFlagEmoji('🇲🇾'));
    }

    public function test_decodes_a_different_country_correctly(): void
    {
        $this->assertSame('PH', CountryCodeDecoder::fromFlagEmoji('🇵🇭'));
    }

    public function test_returns_null_for_text_with_no_flag_emoji(): void
    {
        $this->assertNull(CountryCodeDecoder::fromFlagEmoji('Malaysia'));
    }

    public function test_returns_null_for_null_input(): void
    {
        $this->assertNull(CountryCodeDecoder::fromFlagEmoji(null));
    }

    public function test_returns_null_for_empty_string(): void
    {
        $this->assertNull(CountryCodeDecoder::fromFlagEmoji(''));
    }
}
