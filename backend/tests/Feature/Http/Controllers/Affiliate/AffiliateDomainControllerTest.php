<?php

namespace Tests\Feature\Http\Controllers\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateUser;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Affiliate\Domain\AffiliateDomainProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeAffiliateDomainProvider;
use Tests\TestCase;

/**
 * ADR-060 PR-5 — the affiliate-portal Domain screen. Provider-opaque
 * self-serve add / re-check / set-primary / remove.
 */
class AffiliateDomainControllerTest extends TestCase
{
    use RefreshDatabase;

    private FakeAffiliateDomainProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeAffiliateDomainProvider;
        $this->app->instance(AffiliateDomainProvider::class, $this->provider);

        config()->set('services.vercel.connect_cname', 'connect.pekangame.space');
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

    private function tokenFor(Affiliate $affiliate): string
    {
        $user = AffiliateUser::query()->create([
            'owner_type' => 'affiliate',
            'owner_id' => $affiliate->id,
            'name' => 'Staff',
            'email' => 'staff+'.$affiliate->id.'@acme.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        return $user->createToken('affiliate')->plainTextToken;
    }

    public function test_index_returns_rows_and_provider_opaque_dns_instructions(): void
    {
        $affiliate = $this->affiliate();
        $affiliate->customDomains()->create([
            'hostname' => 'shop.acme.com',
            'status' => AffiliateDomainStatus::Pending,
            'provider' => 'vercel',
            'provider_ref' => 'shop.acme.com',
        ]);

        $response = $this->withToken($this->tokenFor($affiliate))
            ->getJson('/api/affiliate/domains')
            ->assertOk()
            ->assertJsonPath('domains.0.hostname', 'shop.acme.com')
            ->assertJsonPath('max_domains', 5)
            ->assertJsonPath('dns.cname_target', 'connect.pekangame.space');

        $this->assertStringNotContainsStringIgnoringCase('vercel', $response->getContent());
    }

    public function test_store_adds_a_domain(): void
    {
        $affiliate = $this->affiliate();

        $this->withToken($this->tokenFor($affiliate))
            ->postJson('/api/affiliate/domains', ['hostname' => 'shop.acme.com'])
            ->assertCreated()
            ->assertJsonPath('hostname', 'shop.acme.com')
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('affiliate_domains', [
            'affiliate_id' => $affiliate->id,
            'hostname' => 'shop.acme.com',
        ]);
    }

    public function test_store_rejects_a_malformed_hostname(): void
    {
        $affiliate = $this->affiliate();

        $this->withToken($this->tokenFor($affiliate))
            ->postJson('/api/affiliate/domains', ['hostname' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('hostname');
    }

    public function test_store_surfaces_a_sanitised_provider_error(): void
    {
        $affiliate = $this->affiliate();
        $this->provider->failNextAttach = true;

        $response = $this->withToken($this->tokenFor($affiliate))
            ->postJson('/api/affiliate/domains', ['hostname' => 'shop.acme.com'])
            ->assertStatus(422);

        $this->assertStringContainsString('already registered', $response->json('message'));
        $this->assertStringNotContainsStringIgnoringCase('vercel', $response->getContent());
    }

    public function test_recheck_and_set_primary_and_destroy(): void
    {
        $affiliate = $this->affiliate();
        $token = $this->tokenFor($affiliate);

        $this->provider->markVerified('shop.acme.com');
        $create = $this->withToken($token)
            ->postJson('/api/affiliate/domains', ['hostname' => 'shop.acme.com'])
            ->assertCreated();
        $id = $create->json('id');

        $this->withToken($token)->postJson("/api/affiliate/domains/{$id}/recheck")
            ->assertOk()->assertJsonPath('status', 'active');

        $this->withToken($token)->postJson("/api/affiliate/domains/{$id}/primary")
            ->assertOk()->assertJsonPath('is_primary', true);

        $this->withToken($token)->deleteJson("/api/affiliate/domains/{$id}")
            ->assertNoContent();
        $this->assertDatabaseMissing('affiliate_domains', ['id' => $id]);
    }

    public function test_another_affiliates_domain_is_not_reachable(): void
    {
        $mine = $this->affiliate();
        $theirs = $this->affiliate(['email' => 'b@b.test']);
        $row = $theirs->customDomains()->create([
            'hostname' => 'theirs.com',
            'status' => AffiliateDomainStatus::Pending,
            'provider' => 'vercel',
            'provider_ref' => 'theirs.com',
        ]);

        $this->withToken($this->tokenFor($mine))
            ->postJson("/api/affiliate/domains/{$row->id}/recheck")
            ->assertNotFound();
    }

    public function test_a_deactivated_affiliate_screen_is_read_only(): void
    {
        $affiliate = $this->affiliate(['status' => 'inactive']);

        $this->withToken($this->tokenFor($affiliate))
            ->postJson('/api/affiliate/domains', ['hostname' => 'shop.acme.com'])
            ->assertForbidden();
    }
}
