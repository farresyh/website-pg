<?php

namespace App\Services\Cache;

/**
 * ADR-066 2026-11-08 addendum: OPcache, read from inside PHP-FPM, the only
 * place its memory can be seen. A full cache breaks nothing visible: every
 * request just compiles PHP again, about four times slower. So it is reported
 * here, and the console and the tests, which run with OPcache off, never
 * count as full.
 */
class PhpCodeCache
{
    private const BYTES_PER_MB = 1048576;

    /** @return array{active: bool, full: bool, used_mb: float, size_mb: float, hit_rate: float} */
    public function status(): array
    {
        $raw = $this->read();

        if (! is_array($raw) || ! ($raw['opcache_enabled'] ?? false)) {
            return ['active' => false, 'full' => false, 'used_mb' => 0.0, 'size_mb' => 0.0, 'hit_rate' => 0.0];
        }

        $memory = $raw['memory_usage'];
        $used = $memory['used_memory'] + $memory['wasted_memory'];

        return [
            'active' => true,
            'full' => (bool) $raw['cache_full'],
            'used_mb' => round($used / self::BYTES_PER_MB, 1),
            'size_mb' => round(($used + $memory['free_memory']) / self::BYTES_PER_MB, 1),
            'hit_rate' => round($raw['opcache_statistics']['opcache_hit_rate'], 1),
        ];
    }

    public function isFull(): bool
    {
        return $this->status()['full'];
    }

    /** @return array<string, mixed>|false */
    protected function read(): array|false
    {
        return function_exists('opcache_get_status') ? opcache_get_status(false) : false;
    }
}
