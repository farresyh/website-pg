<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-071 PR2 — POSTs the storefront's `/api/revalidate` route to purge
 * its Next.js `catalog` Data-Cache tag, so an admin catalog/SEO/branding
 * change shows within seconds instead of waiting out the 60s TTL.
 * Dispatched by `App\Services\Cache\NextRevalidation::purge()`, which is
 * called next to every backend `forgetCache()`.
 *
 * `ShouldBeUnique` (10s window) collapses a burst of `forget*Cache()`
 * calls — a price sync touching hundreds of rows, a bulk edit — into a
 * single HTTP POST. Purging the whole `catalog` tag once is correct
 * regardless of how many rows changed; the storefront edge just
 * re-fetches on the next request.
 *
 * Best-effort, like `SendMembershipReceiptJob`: if the storefront is
 * unreachable the mutation already succeeded, and the 60s TTL is the
 * backstop. 3 tries, then log and stop — never a `failed_jobs` pile-up.
 */
final class PurgeNextCatalogCache implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 20];

    public int $uniqueFor = 10;

    public function uniqueId(): string
    {
        return 'purge-next-catalog-cache';
    }

    public function handle(): void
    {
        $url = config('services.next.revalidate_url');
        $secret = config('services.next.revalidate_secret');

        if (! is_string($url) || $url === '' || ! is_string($secret) || $secret === '') {
            return;
        }

        $response = Http::timeout((int) config('services.next.revalidate_timeout', 8))
            ->withHeaders(['X-Revalidate-Secret' => $secret])
            ->post($url);

        if ($response->failed()) {
            throw new \RuntimeException(
                "Next revalidate POST returned {$response->status()}: ".mb_substr($response->body(), 0, 200)
            );
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning(
            'PurgeNextCatalogCache permanently failed — the storefront will still refresh on its 60s Data-Cache TTL',
            ['exception' => $e->getMessage()],
        );
    }
}
