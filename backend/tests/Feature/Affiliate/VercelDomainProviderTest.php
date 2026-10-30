<?php

namespace Tests\Feature\Affiliate;

use App\Services\Affiliate\Domain\DomainProviderException;
use App\Services\Affiliate\Domain\VercelDomainProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-060 PR-5 — the Vercel REST calls + provider-opaque error mapping.
 */
class VercelDomainProviderTest extends TestCase
{
    private function provider(): VercelDomainProvider
    {
        return new VercelDomainProvider(
            token: 'tok',
            teamId: 'team_x',
            projectId: 'prj_x',
            baseUrl: 'https://api.vercel.com',
        );
    }

    public function test_attach_maps_the_verification_payload(): void
    {
        Http::fake([
            'api.vercel.com/v10/projects/*/domains*' => Http::response([
                'name' => 'shop.acme.com',
                'verified' => false,
                'verification' => [
                    ['type' => 'TXT', 'domain' => '_vercel.acme.com', 'value' => 'vc-domain-verify=…'],
                ],
            ]),
        ]);

        $state = $this->provider()->attach('shop.acme.com');

        $this->assertFalse($state->verified);
        $this->assertSame('shop.acme.com', $state->providerRef);
        $this->assertCount(1, $state->verification);
    }

    public function test_attach_of_a_taken_domain_is_a_sanitised_already_in_use_error(): void
    {
        Http::fake([
            'api.vercel.com/*' => Http::response(['error' => ['code' => 'domain_already_in_use', 'message' => 'raw provider detail']], 409),
        ]);

        try {
            $this->provider()->attach('shop.acme.com');
            $this->fail('expected DomainProviderException');
        } catch (DomainProviderException $e) {
            $this->assertTrue($e->alreadyInUse);
            $this->assertStringNotContainsString('raw provider detail', $e->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('vercel', $e->getMessage());
        }
    }

    public function test_refresh_returns_missing_on_a_404(): void
    {
        Http::fake(['api.vercel.com/*' => Http::response(['error' => ['code' => 'not_found']], 404)]);

        $state = $this->provider()->refresh('shop.acme.com');

        $this->assertTrue($state->missing);
    }

    public function test_refresh_verifies_a_pending_domain(): void
    {
        Http::fake([
            'api.vercel.com/*/verify*' => Http::response(['verified' => true]),
            'api.vercel.com/v9/projects/*/domains/shop.acme.com*' => Http::response(['name' => 'shop.acme.com', 'verified' => false, 'verification' => []]),
        ]);

        $state = $this->provider()->refresh('shop.acme.com');

        $this->assertTrue($state->verified);
    }

    public function test_detach_tolerates_a_404(): void
    {
        Http::fake(['api.vercel.com/*' => Http::response(['error' => ['code' => 'not_found']], 404)]);

        $this->provider()->detach('shop.acme.com');

        $this->assertTrue(true); // no exception
    }

    public function test_detach_raises_a_generic_error_on_a_500(): void
    {
        Http::fake(['api.vercel.com/*' => Http::response('boom', 500)]);

        $this->expectException(DomainProviderException::class);
        $this->provider()->detach('shop.acme.com');
    }

    public function test_attach_can_register_a_permanent_redirect_to_another_domain(): void
    {
        Http::fake(['api.vercel.com/v10/projects/*/domains*' => Http::response(['name' => 'www.acme.com', 'verified' => false])]);

        $this->provider()->attach('www.acme.com', 'acme.com');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['name'] === 'www.acme.com'
            && $request['redirect'] === 'acme.com'
            && $request['redirectStatusCode'] === 308);
    }

    public function test_a_plain_attach_sends_no_redirect(): void
    {
        Http::fake(['api.vercel.com/v10/projects/*/domains*' => Http::response(['name' => 'acme.com', 'verified' => false])]);

        $this->provider()->attach('acme.com');

        Http::assertSent(fn ($request) => ! isset($request['redirect']) && ! isset($request['redirectStatusCode']));
    }

    public function test_redirect_patches_an_existing_domain_without_re_adding_it(): void
    {
        Http::fake(['api.vercel.com/v9/projects/*/domains/www.acme.com*' => Http::response(['name' => 'www.acme.com', 'verified' => true])]);

        $state = $this->provider()->redirect('www.acme.com', 'acme.com');

        $this->assertTrue($state->verified);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request['redirect'] === 'acme.com'
            && $request['redirectStatusCode'] === 308);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' || $request->method() === 'DELETE');
    }

    public function test_redirect_of_an_unknown_domain_is_missing(): void
    {
        Http::fake(['api.vercel.com/*' => Http::response(['error' => ['code' => 'not_found']], 404)]);

        $this->assertTrue($this->provider()->redirect('www.acme.com', 'acme.com')->missing);
    }
}
