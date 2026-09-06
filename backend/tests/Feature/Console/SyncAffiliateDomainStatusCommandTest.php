<?php

namespace Tests\Feature\Console;

use App\Models\Affiliate;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Affiliate\Domain\AffiliateDomainProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAffiliateDomainProvider;
use Tests\TestCase;

/**
 * ADR-060 PR-5 — the daily custom-domain reconcile / teardown / reminder
 * command.
 */
class SyncAffiliateDomainStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    private FakeAffiliateDomainProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(); // Plunk
        $this->provider = new FakeAffiliateDomainProvider;
        $this->app->instance(AffiliateDomainProvider::class, $this->provider);
        config()->set('services.vercel.stuck_pending_days', 14);
        config()->set('services.plunk.api_key', 'sk_test');
    }

    /** `created_at` is not fillable — force the row's age directly. */
    private function ageRow(int $id, int $days): void
    {
        DB::table('affiliate_domains')->where('id', $id)->update([
            'created_at' => now()->subDays($days)->subHours(2),
        ]);
    }

    private function affiliate(): Affiliate
    {
        return Affiliate::query()->create([
            'business_name' => 'Acme',
            'email' => 'owner@acme.test',
            'markup_pct' => 10,
            'status' => 'active',
        ]);
    }

    public function test_it_activates_a_pending_domain_that_has_verified(): void
    {
        $domain = $this->affiliate()->customDomains()->create([
            'hostname' => 'shop.acme.com',
            'status' => AffiliateDomainStatus::Pending,
            'provider' => 'vercel',
            'provider_ref' => 'shop.acme.com',
        ]);
        $this->provider->markVerified('shop.acme.com');

        $this->artisan('app:sync-affiliate-domain-status')->assertSuccessful();

        $this->assertSame(AffiliateDomainStatus::Active, $domain->fresh()->status);
    }

    public function test_it_tears_down_a_domain_stuck_pending_past_the_ttl(): void
    {
        $domain = $this->affiliate()->customDomains()->create([
            'hostname' => 'shop.acme.com',
            'status' => AffiliateDomainStatus::Pending,
            'provider' => 'vercel',
            'provider_ref' => 'shop.acme.com',
        ]);
        $this->ageRow($domain->id, 15);

        $this->artisan('app:sync-affiliate-domain-status')->assertSuccessful();

        $domain->refresh();
        $this->assertSame(AffiliateDomainStatus::Failed, $domain->status);
        $this->assertNull($domain->provider_ref);
        $this->assertSame(1, $this->provider->opCount('detach'));
    }

    public function test_it_skips_null_provider_ref_rows(): void
    {
        $this->affiliate()->customDomains()->create([
            'hostname' => 'pekangame.space',
            'status' => AffiliateDomainStatus::Active,
            'provider' => 'vercel',
            'provider_ref' => null,
        ]);

        $this->artisan('app:sync-affiliate-domain-status')->assertSuccessful();

        $this->assertSame(0, $this->provider->opCount('refresh'));
    }

    public function test_it_emails_a_reminder_on_day_three(): void
    {
        $this->affiliate()->customDomains()->create([
            'hostname' => 'shop.acme.com',
            'status' => AffiliateDomainStatus::Pending,
            'provider' => 'vercel',
            'provider_ref' => 'shop.acme.com',
        ]);
        $this->ageRow(Affiliate::query()->first()->customDomains()->first()->id, 3);

        $this->artisan('app:sync-affiliate-domain-status')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'useplunk')
            && str_contains($request['subject'] ?? '', 'shop.acme.com'));
    }
}
