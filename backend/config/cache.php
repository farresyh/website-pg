<?php

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    'default' => env('CACHE_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Catalog Packages Store
    |--------------------------------------------------------------------------
    |
    | ADR-027's 2026-08-29 addendum, decision 18: CatalogController's
    | per-game packages cache is scoped to its own store (the `redis`
    | store, using ADR-020 decision 3's already-provisioned `cache`
    | connection) so it can be tag-flushed in one call when a membership
    | tier changes — the app-wide default stays `database` (ADR-014/019),
    | unaffected. Overridden to `array` in phpunit.xml so the fast sqlite
    | test suite never needs a real Redis connection; `array` also
    | supports Cache::tags(), so the invalidation behavior is still
    | exercised in tests, just against an in-memory store.
    |
    */

    'catalog_packages_store' => env('CATALOG_PACKAGES_CACHE_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    |                    "redis", "dynamodb", "storage", "octane",
    |                    "session", "failover", "null"
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'storage' => [
            'driver' => 'storage',
            'disk' => env('CACHE_STORAGE_DISK'),
            'path' => env('CACHE_STORAGE_PATH', 'framework/cache/data'),
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

        'failover' => [
            'driver' => 'failover',
            'stores' => [
                'database',
                'array',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),

    /*
    |--------------------------------------------------------------------------
    | Serializable Classes
    |--------------------------------------------------------------------------
    |
    | This value determines the classes that can be unserialized from cache
    | storage. By default, no PHP classes will be unserialized from your
    | cache to prevent gadget chain attacks if your APP_KEY is leaked.
    |
    | ADR-048 addendum: narrow allowlist, not `true` (blanket-allow), kept
    | consistent with this app's own established discipline
    | (backend/AGENTS.md: "Cache::remember() values must be plain arrays,
    | never a raw Eloquent Model/Collection") — our own code never caches
    | objects, so this list exists only to unblock `laravel/pulse`'s
    | vendor internals (its Livewire dashboard cards cache these 4 exact
    | types via `Cache::flexible()`, confirmed by grepping its source),
    | not to reopen the gadget-chain surface generally. This is a single
    | global setting (`Cache\CacheManager::getSerializableClasses()` reads
    | it for every store, ignoring which store asked), so it applies
    | app-wide, not just to Pulse's own `redis` cache connection — narrow
    | list, not blanket `true`, is what keeps that acceptable. Found live,
    | 2026-08-27/28: every Pulse dashboard card 500'd ("incomplete
    | object... unserialize()") with this at `false`, PHP's own
    | documented behavior for `unserialize(..., ['allowed_classes' =>
    | false])` — silently downgrades every object to
    | `__PHP_Incomplete_Class` instead of erroring at serialize-time, so
    | it wasn't caught by this session's earlier `redis`-cache-driver fix
    | (config/pulse.php) alone.
    |
    */

    'serializable_classes' => [
        Collection::class,
        stdClass::class,
        CarbonImmutable::class,
        CarbonInterval::class,
    ],

];
