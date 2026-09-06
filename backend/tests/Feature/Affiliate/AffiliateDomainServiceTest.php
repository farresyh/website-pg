<?php

namespace Tests\Feature\Affiliate;

use App\Models\Affiliate;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Affiliate\Domain\AffiliateDomainProvider;
use App\Services\Affiliate\Domain\AffiliateDomainService;
use App\Services\Affiliate\Domain\DomainProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeAffiliateDomainProvider;
use Tests\TestCase;

/**
 * ADR-060 PR-5 — the custom-domain lifecycle deep module.
 */
class AffiliateDomainServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeAffiliateDomainProvider $provider;

    private AffiliateDomainService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeAffiliateDomainProvider;
        $this->app->instance(AffiliateDomainProvider::class, $this->provider);
        $this->service = $this->app->make(AffiliateDomainService::class);
    }

    private function affiliate(array $attributes = []): Affiliate
    {
        return Affiliate::query()->create(array_merge([
            'business_name' => 'Acme Resell',
            'email' => 'owner@acme.test',
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ], $attributes));
    }

    public function test_add_creates_a_pending_row_and_attaches_at_the_provider(): void
    {
        $affiliate = $this->affiliate();

        $domain = $this->service->add($affiliate, 'shop.acme.com');

        $this->assertSame(AffiliateDomainStatus::Pending, $domain->status);
        $this->assertSame('shop.acme.com', $domain->provider_ref);
        $this->assertFalse($domain->is_primary);
        $this->assertSame(1, $this->provider->opCount('attach'));
    }

    public function test_add_that_verifies_immediately_becomes_active_and_primary(): void
    {
        $affiliate = $this->affiliate();
        $this->provider->markVerified('shop.acme.com');

        $domain = $this->service->add($affiliate, 'shop.acme.com');

        $this->assertSame(AffiliateDomainStatus::Active, $domain->status);
        $this->assertTrue($domain->is_primary);
        $this->assertNotNull($domain->verified_at);
    }

    public function test_add_normalises_a_pasted_url(): void
    {
        $domain = $this->service->add($this->affiliate(), 'HTTPS://Shop.Acme.com/path');

        $this->assertSame('shop.acme.com', $domain->hostname);
    }

    public function test_add_rejects_a_reserved_hostname(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->add($this->affiliate(), 'anything.pekangame.space');
    }

    public function test_add_rejects_a_malformed_hostname(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->add($this->affiliate(), 'not-a-domain');
    }

    public function test_add_enforces_the_five_domain_cap(): void
    {
        $affiliate = $this->affiliate();

        foreach (range(1, 5) as $i) {
            $this->service->add($affiliate, "shop{$i}.acme.com");
        }

        $this->expectException(ValidationException::class);
        $this->service->add($affiliate, 'shop6.acme.com');
    }

    public function test_a_hostname_belongs_to_exactly_one_affiliate(): void
    {
        $this->service->add($this->affiliate(), 'shop.acme.com');

        $this->expectException(ValidationException::class);
        $this->service->add($this->affiliate(['email' => 'b@b.test']), 'shop.acme.com');
    }

    public function test_a_provider_failure_on_add_rolls_the_row_back(): void
    {
        $affiliate = $this->affiliate();
        $this->provider->failNextAttach = true;

        try {
            $this->service->add($affiliate, 'shop.acme.com');
            $this->fail('expected DomainProviderException');
        } catch (DomainProviderException $e) {
            $this->assertTrue($e->alreadyInUse);
        }

        $this->assertDatabaseMissing('affiliate_domains', ['hostname' => 'shop.acme.com']);
    }

    public function test_recheck_promotes_pending_to_active_and_auto_assigns_primary(): void
    {
        $affiliate = $this->affiliate();
        $domain = $this->service->add($affiliate, 'shop.acme.com');

        $this->provider->markVerified('shop.acme.com');
        $domain = $this->service->recheck($domain);

        $this->assertSame(AffiliateDomainStatus::Active, $domain->status);
        $this->assertTrue($domain->is_primary);
    }

    public function test_recheck_of_a_pulled_primary_fails_over_to_another_active_domain(): void
    {
        $affiliate = $this->affiliate();

        $this->provider->markVerified('a.acme.com');
        $a = $this->service->add($affiliate, 'a.acme.com');
        $this->provider->markVerified('b.acme.com');
        $b = $this->service->add($affiliate, 'b.acme.com');

        $this->assertTrue($a->is_primary);
        $this->assertFalse($b->fresh()->is_primary);

        // `a` loses its DNS.
        $this->provider->markMissing('a.acme.com');
        $this->service->recheck($a);

        $this->assertSame(AffiliateDomainStatus::Failed, $a->fresh()->status);
        $this->assertFalse($a->fresh()->is_primary);
        $this->assertTrue($b->fresh()->is_primary);
    }

    public function test_recheck_skips_a_null_provider_ref_row(): void
    {
        $affiliate = $this->affiliate();
        $row = $affiliate->customDomains()->create([
            'hostname' => 'pekangame.space',
            'status' => AffiliateDomainStatus::Active,
            'provider' => 'vercel',
            'provider_ref' => null,
        ]);

        $this->service->recheck($row);

        $this->assertSame(0, $this->provider->opCount('refresh'));
    }

    public function test_remove_detaches_and_deletes_and_fails_over(): void
    {
        $affiliate = $this->affiliate();
        $this->provider->markVerified('a.acme.com');
        $a = $this->service->add($affiliate, 'a.acme.com');
        $this->provider->markVerified('b.acme.com');
        $b = $this->service->add($affiliate, 'b.acme.com');

        $this->service->remove($a);

        $this->assertDatabaseMissing('affiliate_domains', ['hostname' => 'a.acme.com']);
        $this->assertSame(1, $this->provider->opCount('detach'));
        $this->assertTrue($b->fresh()->is_primary);
    }

    public function test_set_primary_only_accepts_an_active_domain(): void
    {
        $affiliate = $this->affiliate();
        $pending = $this->service->add($affiliate, 'shop.acme.com');

        $this->expectException(ValidationException::class);
        $this->service->setPrimary($pending);
    }

    public function test_suspend_all_and_resume_all(): void
    {
        $affiliate = $this->affiliate();
        $this->provider->markVerified('shop.acme.com');
        $domain = $this->service->add($affiliate, 'shop.acme.com');

        $this->service->suspendAll($affiliate);
        $this->assertSame(AffiliateDomainStatus::Suspended, $domain->fresh()->status);
        $this->assertFalse($domain->fresh()->is_primary);

        $this->provider->markVerified('shop.acme.com');
        $this->service->resumeAll($affiliate);
        $this->assertSame(AffiliateDomainStatus::Active, $domain->fresh()->status);
    }

    public function test_remove_all_for_delete_detaches_every_provider_domain(): void
    {
        $affiliate = $this->affiliate();
        $this->service->add($affiliate, 'a.acme.com');
        $this->service->add($affiliate, 'b.acme.com');

        $this->service->removeAllForDelete($affiliate);

        $this->assertSame(0, $affiliate->customDomains()->count());
        $this->assertSame(2, $this->provider->opCount('detach'));
    }

    public function test_tear_down_stuck_pending_marks_failed_and_clears_provider_ref(): void
    {
        $affiliate = $this->affiliate();
        $domain = $this->service->add($affiliate, 'shop.acme.com');

        $this->service->tearDownStuckPending($domain);

        $domain->refresh();
        $this->assertSame(AffiliateDomainStatus::Failed, $domain->status);
        $this->assertNull($domain->provider_ref);
        $this->assertSame(1, $this->provider->opCount('detach'));
    }
}
