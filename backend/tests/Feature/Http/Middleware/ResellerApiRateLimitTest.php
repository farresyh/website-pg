<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Reseller;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\ResellerApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ADR-074 2026-10-13 addendum (item 6/A3): the Reseller API is limited per
 * Reseller account, not per key, in two buckets — orders (POST /orders,
 * 60/min) and reads (every GET, 120/min). An order POST with an empty body
 * is a 422 that still counts, so these tests need no funded wallet.
 */
class ResellerApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function makeReseller(string $name = 'Acme'): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => $name, 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }

    private function issueKey(Reseller $reseller): string
    {
        return app(ResellerApiKeyService::class)->issue($reseller, 'Test key')['plainText'];
    }

    private function order(string $key): TestResponse
    {
        return $this->postJson('/api/reseller/v1/orders', [], ['Authorization' => "Bearer {$key}"]);
    }

    private function read(string $key): TestResponse
    {
        return $this->getJson('/api/reseller/v1/balance', ['Authorization' => "Bearer {$key}"]);
    }

    public function test_every_key_of_one_reseller_shares_one_order_bucket(): void
    {
        $reseller = $this->makeReseller();
        $first = $this->issueKey($reseller);
        $second = $this->issueKey($reseller);

        for ($i = 0; $i < 30; $i++) {
            $this->order($first)->assertUnprocessable();
            $this->order($second)->assertUnprocessable();
        }

        $this->order($first)
            ->assertStatus(429)
            ->assertJsonPath('error', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
    }

    public function test_reads_have_their_own_bucket_and_never_block_orders(): void
    {
        $key = $this->issueKey($this->makeReseller());

        for ($i = 0; $i < 120; $i++) {
            $this->read($key)->assertOk();
        }

        $this->read($key)->assertStatus(429)->assertJsonPath('error', 'RATE_LIMITED');
        $this->order($key)->assertUnprocessable();
    }

    public function test_orders_have_their_own_bucket_and_never_block_reads(): void
    {
        $key = $this->issueKey($this->makeReseller());

        for ($i = 0; $i < 60; $i++) {
            $this->order($key)->assertUnprocessable();
        }

        $this->order($key)->assertStatus(429);
        $this->read($key)->assertOk()->assertHeader('X-RateLimit-Limit', '120');
    }

    public function test_one_resellers_limit_never_touches_another(): void
    {
        $busy = $this->issueKey($this->makeReseller('Busy'));
        $quiet = $this->issueKey($this->makeReseller('Quiet'));

        for ($i = 0; $i < 60; $i++) {
            $this->order($busy)->assertUnprocessable();
        }

        $this->order($busy)->assertStatus(429);
        $this->order($quiet)->assertUnprocessable();
    }

    public function test_a_revoked_and_reissued_key_does_not_reset_the_bucket(): void
    {
        $reseller = $this->makeReseller();
        $old = $this->issueKey($reseller);

        for ($i = 0; $i < 60; $i++) {
            $this->order($old)->assertUnprocessable();
        }

        app(ResellerApiKeyService::class)->revoke($reseller->apiKeys()->firstOrFail());

        $this->order($this->issueKey($reseller))->assertStatus(429);
    }
}
