<?php

namespace Tests\Unit\Services\Cache;

use App\Services\Cache\PhpCodeCache;
use PHPUnit\Framework\TestCase;

class PhpCodeCacheTest extends TestCase
{
    /** @param array<string, mixed>|false $raw */
    private function cache(array|false $raw): PhpCodeCache
    {
        return new class($raw) extends PhpCodeCache
        {
            /** @param array<string, mixed>|false $raw */
            public function __construct(private readonly array|false $raw) {}

            protected function read(): array|false
            {
                return $this->raw;
            }
        };
    }

    /** @return array<string, mixed> */
    private function raw(bool $full): array
    {
        return [
            'opcache_enabled' => true,
            'cache_full' => $full,
            'memory_usage' => ['used_memory' => 96 * 1048576, 'free_memory' => 32 * 1048576, 'wasted_memory' => 0],
            'opcache_statistics' => ['opcache_hit_rate' => 22.6374],
        ];
    }

    public function test_a_full_cache_is_reported_with_its_size_and_hit_rate(): void
    {
        $cache = $this->cache($this->raw(full: true));

        $this->assertTrue($cache->isFull());
        $this->assertSame(
            ['active' => true, 'full' => true, 'used_mb' => 96.0, 'size_mb' => 128.0, 'hit_rate' => 22.6],
            $cache->status(),
        );
    }

    public function test_a_cache_with_room_is_not_full(): void
    {
        $this->assertFalse($this->cache($this->raw(full: false))->isFull());
    }

    /** The console and the test suite run with OPcache off: that must never read as a fault. */
    public function test_no_opcache_is_not_a_full_cache(): void
    {
        foreach ([false, ['opcache_enabled' => false]] as $raw) {
            $cache = $this->cache($raw);

            $this->assertFalse($cache->isFull());
            $this->assertFalse($cache->status()['active']);
        }
    }
}
