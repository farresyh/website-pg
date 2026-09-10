<?php

namespace Tests\Feature\Services\Reseller;

use App\Models\Reseller;
use App\Services\Reseller\ResellerApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-074 decision 1: generate-once-show-once, hash-only-at-rest. */
class ResellerApiKeyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(): Reseller
    {
        return Reseller::query()->create(['business_name' => 'Acme Reseller', 'is_active' => true]);
    }

    public function test_issue_returns_a_plaintext_key_and_persists_only_its_hash(): void
    {
        $reseller = $this->reseller();

        $issued = app(ResellerApiKeyService::class)->issue($reseller, 'Production key');

        $this->assertStringStartsWith('pgrk_', $issued['plainText']);
        $this->assertSame('Production key', $issued['key']->name);
        $this->assertNotSame($issued['plainText'], $issued['key']->key_hash);
        $this->assertSame(hash('sha256', $issued['plainText']), $issued['key']->key_hash);
    }

    public function test_resolve_finds_the_key_by_its_plaintext(): void
    {
        $reseller = $this->reseller();
        $service = app(ResellerApiKeyService::class);
        $issued = $service->issue($reseller, 'Production key');

        $resolved = $service->resolve($issued['plainText']);

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($issued['key']));
    }

    public function test_resolve_touches_last_used_at(): void
    {
        $reseller = $this->reseller();
        $service = app(ResellerApiKeyService::class);
        $issued = $service->issue($reseller, 'Production key');
        $this->assertNull($issued['key']->last_used_at);

        $service->resolve($issued['plainText']);

        $this->assertNotNull($issued['key']->refresh()->last_used_at);
    }

    public function test_resolve_records_last_used_ip_when_given(): void
    {
        $reseller = $this->reseller();
        $service = app(ResellerApiKeyService::class);
        $issued = $service->issue($reseller, 'Production key');

        $service->resolve($issued['plainText'], '203.0.113.42');

        $this->assertSame('203.0.113.42', $issued['key']->refresh()->last_used_ip);
    }

    public function test_resolve_leaves_last_used_ip_untouched_when_no_ip_is_given(): void
    {
        $reseller = $this->reseller();
        $service = app(ResellerApiKeyService::class);
        $issued = $service->issue($reseller, 'Production key');
        $issued['key']->update(['last_used_ip' => '198.51.100.1']);

        $service->resolve($issued['plainText']);

        $this->assertSame('198.51.100.1', $issued['key']->refresh()->last_used_ip);
    }

    public function test_resolve_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull(app(ResellerApiKeyService::class)->resolve('pgrk_does-not-exist'));
    }

    public function test_resolve_returns_null_for_a_revoked_key(): void
    {
        $reseller = $this->reseller();
        $service = app(ResellerApiKeyService::class);
        $issued = $service->issue($reseller, 'Production key');

        $service->revoke($issued['key']);

        $this->assertNull($service->resolve($issued['plainText']));
    }

    public function test_revoke_is_idempotent(): void
    {
        $reseller = $this->reseller();
        $service = app(ResellerApiKeyService::class);
        $issued = $service->issue($reseller, 'Production key');

        $service->revoke($issued['key']);
        $firstRevokedAt = $issued['key']->refresh()->revoked_at;
        $service->revoke($issued['key']->refresh());

        $this->assertTrue($firstRevokedAt->equalTo($issued['key']->refresh()->revoked_at));
    }
}
