<?php

namespace Tests\Feature\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Affiliate\Domain\AffiliateDomainProvider;
use App\Services\Affiliate\Domain\AffiliateDomainService;
use App\Services\Affiliate\Domain\DomainProviderException;
use App\Services\Cors\ActiveCustomDomainOrigins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeAffiliateDomainProvider;
use Tests\TestCase;

/**
 * ADR-060 second 2026-10-30 addendum: a domain brings a `www` redirect
 * twin — a row (`redirects_to_domain_id`) that 308-redirects to the typed
 * domain and is never served, canonical, primary, CORS-allowed or
 * payment-approved.
 */
class AffiliateDomainWwwTwinTest extends TestCase
{
    use RefreshDatabase;

    private FakeAffiliateDomainProvider $provider;

    private AffiliateDomainService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(); // Plunk
        $this->primaryAffiliate();
        $this->provider = new FakeAffiliateDomainProvider;
        $this->app->instance(AffiliateDomainProvider::class, $this->provider);
        $this->service = $this->app->make(AffiliateDomainService::class);
        config()->set('services.vercel.stuck_pending_days', 14);
        config()->set('services.plunk.api_key', 'sk_test');
    }

    private function affiliate(string $name = 'Acme'): Affiliate
    {
        return Affiliate::query()->create([
            'business_name' => $name, 'email' => strtolower($name).'@owner.test', 'markup_pct' => 10, 'status' => 'active',
        ]);
    }

    /** An apex that is already live (verified) with its twin added. */
    private function apexWithTwin(Affiliate $affiliate, string $apex = 'acme.com', bool $apexLive = true): array
    {
        if ($apexLive) {
            $this->provider->markVerified($apex);
        }
        $domain = $this->service->add($affiliate, $apex);
        $twin = $this->service->pairWww($domain);

        return [$domain->refresh(), $twin];
    }

    public function test_pair_www_creates_a_twin_that_redirects_to_the_typed_domain(): void
    {
        $affiliate = $this->affiliate();
        $apex = $this->service->add($affiliate, 'acme.com');

        $twin = $this->service->pairWww($apex);

        $this->assertSame('www.acme.com', $twin->hostname);
        $this->assertSame($apex->id, $twin->redirects_to_domain_id);
        $this->assertSame(AffiliateDomainStatus::Pending, $twin->status);
        $this->assertFalse($twin->is_primary);
        $attach = collect($this->provider->calls)->firstWhere('hostname', 'www.acme.com');
        $this->assertSame(['op' => 'attach', 'hostname' => 'www.acme.com', 'redirectTo' => 'acme.com'], $attach);
    }

    public function test_pair_www_is_idempotent(): void
    {
        [$apex, $twin] = $this->apexWithTwin($this->affiliate());
        $attaches = $this->provider->opCount('attach');

        $again = $this->service->pairWww($apex);

        $this->assertSame($twin->id, $again->id);
        $this->assertSame($attaches, $this->provider->opCount('attach'));
        $this->assertSame(0, $this->provider->opCount('redirect'));
    }

    public function test_pair_www_refuses_a_hostname_that_is_already_www(): void
    {
        $www = $this->service->add($this->affiliate(), 'www.acme.com');

        $this->expectException(ValidationException::class);
        $this->service->pairWww($www);
    }

    public function test_pair_www_refuses_when_another_affiliate_owns_the_www_hostname(): void
    {
        $other = $this->affiliate('Other');
        $this->service->add($other, 'www.acme.com');
        $apex = $this->service->add($this->affiliate(), 'acme.com');

        try {
            $this->service->pairWww($apex);
            $this->fail('expected ValidationException');
        } catch (ValidationException) {
            $this->assertSame(0, AffiliateDomain::query()->whereNotNull('redirects_to_domain_id')->count());
        }
    }

    public function test_a_provider_failure_on_the_twin_leaves_no_twin_row_and_the_apex_intact(): void
    {
        $apex = $this->service->add($this->affiliate(), 'acme.com');
        $this->provider->willFail('www.acme.com');

        try {
            $this->service->pairWww($apex);
            $this->fail('expected DomainProviderException');
        } catch (DomainProviderException) {
            $this->assertDatabaseMissing('affiliate_domains', ['hostname' => 'www.acme.com']);
            $this->assertDatabaseHas('affiliate_domains', ['hostname' => 'acme.com']);
        }
    }

    public function test_a_twin_that_verifies_first_is_never_made_primary(): void
    {
        $affiliate = $this->affiliate();
        $apex = $this->service->add($affiliate, 'acme.com');
        $this->provider->markVerified('www.acme.com');

        $twin = $this->service->pairWww($apex);

        $this->assertSame(AffiliateDomainStatus::Active, $twin->status);
        $this->assertFalse($twin->is_primary);

        $this->provider->markVerified('acme.com');
        $this->assertTrue($this->service->recheck($apex->refresh())->is_primary);
    }

    public function test_the_primary_never_fails_over_to_a_twin(): void
    {
        [$apex, $twin] = $this->apexWithTwin($this->affiliate());
        $this->provider->markVerified('www.acme.com');
        $this->service->recheck($twin);
        $this->assertTrue($apex->refresh()->is_primary);

        $this->provider->markMissing('acme.com'); // the apex loses its DNS
        $this->service->recheck($apex);

        $this->assertSame(0, AffiliateDomain::query()->where('is_primary', true)->count());
    }

    public function test_set_primary_on_a_twin_is_refused(): void
    {
        $this->provider->markVerified('www.acme.com');
        [, $twin] = $this->apexWithTwin($this->affiliate());

        $this->expectException(ValidationException::class);
        $this->service->setPrimary($twin->refresh());
    }

    public function test_a_twin_is_not_a_canonical_origin_a_cors_origin_or_a_resolvable_host(): void
    {
        $affiliate = $this->affiliate();
        $this->provider->markVerified('www.acme.com');
        [$apex, $twin] = $this->apexWithTwin($affiliate, apexLive: false); // only the twin is live
        $this->assertSame(AffiliateDomainStatus::Active, $twin->refresh()->status);

        $this->assertSame(rtrim((string) config('services.storefront.url'), '/'), $affiliate->canonicalOrigin());
        $this->assertNotContains('https://www.acme.com', app(ActiveCustomDomainOrigins::class)->all());
        $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => 'www.acme.com'])
            ->assertNotFound()
            ->assertJsonPath('code', 'unknown_storefront_host');
    }

    public function test_the_domain_cap_counts_only_domains_the_affiliate_chose(): void
    {
        $affiliate = $this->affiliate();
        foreach (['a.com', 'b.com', 'c.com', 'd.com', 'e.com'] as $host) {
            $this->service->pairWww($this->service->add($affiliate, $host));
        }

        $this->assertSame(10, $affiliate->customDomains()->count());
        $this->expectException(ValidationException::class);
        $this->service->add($affiliate, 'f.com');
    }

    public function test_removing_the_apex_detaches_the_twin_first_and_deletes_both(): void
    {
        [$apex] = $this->apexWithTwin($this->affiliate());

        $this->service->remove($apex);

        $this->assertSame(['www.acme.com', 'acme.com'], $this->provider->hostnamesFor('detach'));
        $this->assertSame(0, AffiliateDomain::query()->count());
    }

    public function test_removing_only_the_twin_leaves_the_apex(): void
    {
        [$apex, $twin] = $this->apexWithTwin($this->affiliate());

        $this->service->remove($twin);

        $this->assertDatabaseHas('affiliate_domains', ['id' => $apex->id]);
        $this->assertDatabaseMissing('affiliate_domains', ['id' => $twin->id]);
    }

    public function test_deleting_an_affiliate_detaches_twins_before_their_targets(): void
    {
        $affiliate = $this->affiliate();
        $this->apexWithTwin($affiliate);

        $this->service->removeAllForDelete($affiliate);

        $this->assertSame(['www.acme.com', 'acme.com'], $this->provider->hostnamesFor('detach'));
        $this->assertSame(0, AffiliateDomain::query()->count());
    }

    public function test_tearing_down_a_stuck_apex_takes_its_twin_with_it(): void
    {
        [$apex] = $this->apexWithTwin($this->affiliate(), apexLive: false);

        $this->service->tearDownStuckPending($apex);

        $this->assertDatabaseMissing('affiliate_domains', ['hostname' => 'www.acme.com']);
        $this->assertSame(AffiliateDomainStatus::Failed, $apex->refresh()->status);
        $this->assertSame(['www.acme.com', 'acme.com'], $this->provider->hostnamesFor('detach'));
    }

    public function test_an_existing_www_domain_is_converted_in_place_through_a_redirect_call(): void
    {
        $affiliate = $this->affiliate();
        $this->provider->markVerified('acme.com');
        $apex = $this->service->add($affiliate, 'acme.com');
        // The hand-added www domain (OhahaStore's case): active, serving, payments approved, once primary.
        $this->provider->markVerified('www.acme.com');
        $www = $this->service->add($affiliate, 'www.acme.com');
        $www->forceFill(['payments_approved_at' => now(), 'is_primary' => true])->save();
        $apex->forceFill(['is_primary' => false])->save();
        $attaches = $this->provider->opCount('attach');

        $twin = $this->service->pairWww($apex);

        $this->assertSame($www->id, $twin->id);
        $this->assertSame($apex->id, $twin->redirects_to_domain_id);
        $this->assertSame(AffiliateDomainStatus::Active, $twin->status);
        $this->assertFalse($twin->is_primary);
        $this->assertSame($attaches, $this->provider->opCount('attach')); // converted by PATCH, never re-added
        $this->assertSame(['www.acme.com'], $this->provider->hostnamesFor('redirect'));
        $this->assertTrue($apex->refresh()->is_primary); // the primary moved to the live apex
    }

    public function test_the_reminder_emails_skip_a_twin(): void
    {
        [, $twin] = $this->apexWithTwin($this->affiliate(), apexLive: false);
        DB::table('affiliate_domains')->where('id', $twin->id)->update(['created_at' => now()->subDays(3)->subHours(2)]);
        DB::table('affiliate_domains')->where('id', '!=', $twin->id)->update(['created_at' => now()->subHour()]);

        $this->artisan('app:sync-affiliate-domain-status')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'useplunk'));
    }
}
