<?php

namespace Tests\Unit\Support;

use App\Support\OutboundUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Wave 3 S-3: a reseller-supplied webhook URL must never reach the
 * droplet's own network (loopback, private ranges, DO's metadata
 * service) — whether written as an IP literal or via DNS that resolves
 * there.
 */
class OutboundUrlGuardTest extends TestCase
{
    private function guard(array $dns): OutboundUrlGuard
    {
        return new OutboundUrlGuard(fn (string $host) => $dns[$host] ?? []);
    }

    /** @return array<string, array{string}> */
    public static function nonPublicUrls(): array
    {
        return [
            'loopback literal' => ['https://127.0.0.1/hook'],
            'metadata service' => ['https://169.254.169.254/latest/meta-data'],
            'private range' => ['https://10.0.0.5/hook'],
            'ipv6 loopback' => ['https://[::1]/hook'],
            'hostname resolving to loopback' => ['https://evil.example/hook'],
            'hostname with one private record among public ones' => ['https://mixed.example/hook'],
            'unresolvable hostname' => ['https://nowhere.example/hook'],
            'no host at all' => ['not-a-url'],
        ];
    }

    #[DataProvider('nonPublicUrls')]
    public function test_rejects_a_url_that_reaches_a_non_public_address(string $url): void
    {
        $guard = $this->guard([
            'evil.example' => ['127.0.0.1'],
            'mixed.example' => ['93.184.215.14', '10.0.0.7'],
        ]);

        $this->assertNull($guard->publicAddressFor($url));
    }

    public function test_returns_the_resolved_public_address_to_pin(): void
    {
        $guard = $this->guard(['hooks.example' => ['93.184.215.14']]);

        $this->assertSame('93.184.215.14', $guard->publicAddressFor('https://hooks.example/hook'));
    }

    public function test_a_public_ip_literal_needs_no_lookup(): void
    {
        $guard = new OutboundUrlGuard(fn () => throw new \LogicException('must not resolve an IP literal'));

        $this->assertSame('93.184.215.14', $guard->publicAddressFor('https://93.184.215.14/hook'));
    }
}
