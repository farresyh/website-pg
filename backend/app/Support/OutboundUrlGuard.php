<?php

namespace App\Support;

use Closure;

/**
 * Wave 3 S-3 (ADR-074 addendum 2026-09-29): SSRF guard for a URL a
 * reseller controls (the delivery webhook). A URL is only callable when
 * its host — an IP literal, or EVERY address its DNS resolves to — is a
 * globally routable address: never loopback, private, link-local (DO's
 * 169.254.169.254 metadata service), CGNAT or reserved.
 *
 * Checked when the URL is saved and again at send time, since DNS can be
 * repointed in between. The send-time caller pins the connection to the
 * address checked here (CURLOPT_RESOLVE), so a second, different DNS
 * answer can't slip in between the check and the connect.
 */
final class OutboundUrlGuard
{
    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /** @param  (Closure(string): list<string>)|null  $resolver  tests swap DNS here */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::resolveDns(...);
    }

    /** The public address to connect to, or null if the URL must not be called. */
    public function publicAddressFor(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : ($this->resolver)($host);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                return null;
            }
        }

        return $addresses[0];
    }

    /** @return list<string> */
    private static function resolveDns(string $host): array
    {
        $v4 = gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return [...$v4, ...$v6];
    }
}
