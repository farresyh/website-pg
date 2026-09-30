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

    public function test_same_never_matches_two_unusable_numbers(): void
    {
        $this->assertTrue(PhoneNumber::same('0123456789', '+60 12-345 6789'));
        $this->assertFalse(PhoneNumber::same(null, null));
        $this->assertFalse(PhoneNumber::same('12', '12'));
    }
}
