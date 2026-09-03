<?php

namespace Tests\Feature\Jobs;

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\HeroSlideController;
use App\Jobs\PurgeNextCatalogCache;
use App\Services\Cache\NextRevalidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADR-071 PR2 — the storefront Next.js `catalog` Data-Cache purge.
 */
class PurgeNextCatalogCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_next_revalidation_is_a_noop_when_unconfigured(): void
    {
        config(['services.next.revalidate_url' => null, 'services.next.revalidate_secret' => null]);
        Queue::fake();

        NextRevalidation::purge();

        Queue::assertNothingPushed();
    }

    public function test_next_revalidation_dispatches_the_job_when_configured(): void
    {
        config([
            'services.next.revalidate_url' => 'https://store.example/api/revalidate',
            'services.next.revalidate_secret' => 'shhh',
        ]);
        Queue::fake();

        NextRevalidation::purge();

        Queue::assertPushed(PurgeNextCatalogCache::class, 1);
    }

    public function test_job_posts_the_secret_header_to_the_revalidate_url(): void
    {
        config([
            'services.next.revalidate_url' => 'https://store.example/api/revalidate',
            'services.next.revalidate_secret' => 'shhh',
        ]);
        Http::fake(['store.example/*' => Http::response(['revalidated' => true], 200)]);

        (new PurgeNextCatalogCache)->handle();

        Http::assertSent(fn ($request) => $request->url() === 'https://store.example/api/revalidate'
            && $request->method() === 'POST'
            && $request->header('X-Revalidate-Secret') === ['shhh']);
    }

    public function test_job_throws_on_a_failed_response_so_it_retries(): void
    {
        config([
            'services.next.revalidate_url' => 'https://store.example/api/revalidate',
            'services.next.revalidate_secret' => 'shhh',
        ]);
        Http::fake(['store.example/*' => Http::response('nope', 500)]);

        $this->expectException(\RuntimeException::class);

        (new PurgeNextCatalogCache)->handle();
    }

    public function test_job_is_a_noop_when_unconfigured_at_run_time(): void
    {
        config(['services.next.revalidate_url' => null, 'services.next.revalidate_secret' => null]);
        Http::fake();

        (new PurgeNextCatalogCache)->handle();

        Http::assertNothingSent();
    }

    public function test_permanent_failure_is_logged_not_fatal(): void
    {
        Log::spy();

        (new PurgeNextCatalogCache)->failed(new \RuntimeException('boom'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'PurgeNextCatalogCache permanently failed'))
            ->once();
    }

    public function test_a_catalog_forget_cache_seam_triggers_a_purge(): void
    {
        config([
            'services.next.revalidate_url' => 'https://store.example/api/revalidate',
            'services.next.revalidate_secret' => 'shhh',
        ]);
        Queue::fake();

        HeroSlideController::forgetCache();

        Queue::assertPushed(PurgeNextCatalogCache::class, 1);
    }

    public function test_a_burst_of_forget_cache_calls_collapses_to_one_purge(): void
    {
        // ShouldBeUnique: a price sync touching hundreds of rows, or a
        // bulk edit, fires forget*Cache() many times — only one POST goes
        // out (purging the whole `catalog` tag once is correct anyway).
        config([
            'services.next.revalidate_url' => 'https://store.example/api/revalidate',
            'services.next.revalidate_secret' => 'shhh',
        ]);
        Queue::fake();

        CatalogController::forgetIndexCache();
        CatalogController::forgetPackagesCache(1);
        CatalogController::forgetPackagesCache(2);
        CatalogController::forgetPackagesCacheForMembership();

        Queue::assertPushed(PurgeNextCatalogCache::class, 1);
    }
}
