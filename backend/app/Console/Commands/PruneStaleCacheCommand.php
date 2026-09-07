<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-077 decision 3 — `Illuminate\Cache\DatabaseStore` expires entries
 * lazily on read only: a key that is written, expires, and is never read
 * again leaves its row in the `cache` table forever. Laravel ships no
 * sweeper for it — `cache:prune-stale-tags` is Redis-only and touches
 * only tag sets. ADR-077 decision 1 moved the default store to `redis`,
 * so on the production path this table is near-inert and this command is
 * a 0-row no-op; it exists to keep the `cache` table from bloating
 * during the cutover window and in any environment deliberately left on
 * CACHE_STORE=database.
 *
 * Scoped to the `database` store's own table — never touches Redis, and
 * never touches `cache_locks` (a separate table with its own lifecycle).
 */
#[Signature('app:prune-stale-cache')]
#[Description('Delete expired rows from the database cache store table.')]
class PruneStaleCacheCommand extends Command
{
    public function handle(): int
    {
        $connection = config('cache.stores.database.connection') ?: config('database.default');
        $table = config('cache.stores.database.table', 'cache');

        if (! Schema::connection($connection)->hasTable($table)) {
            $this->info("Skipped — table `{$table}` does not exist on connection `{$connection}`.");

            return self::SUCCESS;
        }

        $deleted = DB::connection($connection)
            ->table($table)
            ->where('expiration', '<=', now()->getTimestamp())
            ->delete();

        $this->info("Pruned {$deleted} expired row(s) from `{$table}`.");
        Log::info('Pruned stale database cache rows', [
            'connection' => $connection,
            'table' => $table,
            'deleted' => $deleted,
        ]);

        return self::SUCCESS;
    }
}
