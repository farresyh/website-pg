<?php

namespace Tests\Unit\Services\PlayerValidation;

use App\Services\PlayerValidation\CountryCodeDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CountryCodeDecoderTest extends TestCase
{
    /**
     * Every name here was returned by RedxGame for a real account on
     * 2026-10-16, except Myanmar (the founder's word for how Redx spells it).
     */
    public static function redxNames(): array
    {
        return [
            ['Malaysia', 'MY'],
            ['Indonesia', 'ID'],
            ['Russia', 'RU'],
            ['Brazil', 'BR'],
            ['South Korea', 'KR'],
            ['Turkey', 'TR'],
            ['Myanmar', 'MM'],
        ];
    }

    #[DataProvider('redxNames')]
    public function test_maps_a_country_name_to_its_iso_code(string $name, string $code): void
    {
        $this->assertSame($code, CountryCodeDecoder::fromCountryName($name));
    }

    public function test_ignores_case_and_surrounding_whitespace(): void
    {
        $this->assertSame('MY', CountryCodeDecoder::fromCountryName('  malaysia '));
    }

    /**
     * ICU still names retired codes (BU = Burma, SU = USSR, DD = East
     * Germany) with today's country names; only codes in current use
     * may come back.
     */
    public function test_never_returns_a_retired_code(): void
    {
        $this->assertSame('MM', CountryCodeDecoder::fromCountryName('Myanmar (Burma)'));
        $this->assertSame('RU', CountryCodeDecoder::fromCountryName('Russia'));
        $this->assertSame('DE', CountryCodeDecoder::fromCountryName('Germany'));
    }

    public function test_returns_null_for_an_unknown_name(): void
    {
        $this->assertNull(CountryCodeDecoder::fromCountryName('Atlantis'));
    }

    public function test_returns_null_for_null_or_empty_input(): void
    {
        $this->assertNull(CountryCodeDecoder::fromCountryName(null));
        $this->assertNull(CountryCodeDecoder::fromCountryName('  '));
    }
}
