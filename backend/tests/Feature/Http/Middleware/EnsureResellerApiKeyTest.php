<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Reseller;
use App\Models\ResellerApiKey;
use App\Models\ResellerTier;
use App\Services\Reseller\ResellerApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-084 PR-1 decision 6: the per-key IP allowlist + `last_used_ip`. */
class EnsureResellerApiKeyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ResellerApiKey, 1: string} */
    private function issueKey(?array $allowedIps = null): array
    {
        $tier = ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => 10, 'is_active' => true, 'sort_order' => 1]);
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'reseller_tier_id' => $tier->id, 'is_active' => true]);
        $issued = app(ResellerApiKeyService::class)->issue($reseller, 'Test key');

        if ($allowedIps !== null) {
            $issued['key']->update(['allowed_ips' => $allowedIps]);
        }

        return [$issued['key']->fresh(), $issued['plainText']];
    }

    private function hit(string $key, string $fromIp)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $fromIp])
            ->getJson('/api/reseller/v1/balance', ['Authorization' => "Bearer {$key}"]);
    }

    public function test_an_empty_allowlist_permits_any_ip(): void
    {
        [, $key] = $this->issueKey(allowedIps: []);

        $this->hit($key, '203.0.113.9')->assertOk();
    }

    public function test_a_null_allowlist_permits_any_ip(): void
    {
        [, $key] = $this->issueKey(allowedIps: null);

        $this->hit($key, '203.0.113.9')->assertOk();
    }

    public function test_an_ip_on_the_allowlist_is_permitted(): void
    {
        [, $key] = $this->issueKey(allowedIps: ['203.0.113.9', '198.51.100.1']);

        $this->hit($key, '198.51.100.1')->assertOk();
    }

    public function test_an_ip_not_on_the_allowlist_is_403_ip_not_allowed(): void
    {
        [, $key] = $this->issueKey(allowedIps: ['203.0.113.9']);

        $this->hit($key, '198.51.100.7')
            ->assertForbidden()
            ->assertJsonPath('error', 'IP_NOT_ALLOWED');
    }

    public function test_the_calling_ip_is_recorded_even_when_the_allowlist_rejects_it(): void
    {
        [$apiKey, $key] = $this->issueKey(allowedIps: ['203.0.113.9']);

        $this->hit($key, '198.51.100.7')->assertForbidden();

        $this->assertSame('198.51.100.7', $apiKey->fresh()->last_used_ip);
    }
}
