<?php

namespace Tests\Unit\Support;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ADR-116 decision 8. */
class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{?string, ?string}> */
    public static function cases(): array
    {
        return [
            'local with trunk 0' => ['0123456789', '60123456789'],
            'separators' => ['012-345 6789', '60123456789'],
            'plus country code' => ['+60123456789', '60123456789'],
            'bare country code' => ['60123456789', '60123456789'],
            'international 00 prefix' => ['0060123456789', '60123456789'],
            'foreign number kept' => ['+65 8275 1992', '6582751992'],
            // A Malaysian mobile typed without its trunk 0 (production, 2026-10-30): 9 or 10 digits starting with 1.
            'mobile without the trunk 0, 10 digits' => ['1123456267', '601123456267'],
            'mobile without the trunk 0, 9 digits' => ['123456789', '60123456789'],
            'mobile without the trunk 0, with separators' => ['11-2345 6267', '601123456267'],
            // Country code 1 numbers are always 11 digits, so they are never mistaken for the above.
            'north american number kept' => ['+1 415 555 2671', '14155552671'],
            'north american number without plus kept' => ['14155552671', '14155552671'],
            'too short' => ['12', null],
            'too long' => ['1234567890123456', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('cases')]
    public function test_normalize(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    public function test_every_way_of_typing_one_malaysian_mobile_is_the_same_number(): void
    {
        $typed = ['01123456267', '1123456267', '601123456267', '+60 11-2345 6267', '0060 11 2345 6267'];

        foreach ($typed as $a) {
            foreach ($typed as $b) {
                $this->assertTrue(PhoneNumber::same($a, $b), "{$a} vs {$b}");
            }
        }
    }

    public function test_same_never_matches_two_unusable_numbers(): void
    {
        $this->assertTrue(PhoneNumber::same('0123456789', '+60 12-345 6789'));
        $this->assertFalse(PhoneNumber::same(null, null));
        $this->assertFalse(PhoneNumber::same('12', '12'));
    }
}
