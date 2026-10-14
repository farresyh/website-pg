<?php

namespace Tests\Feature\Http\Controllers\ResellerApi;

use App\Models\Reseller;
use App\Models\ResellerApiKey;
use App\Models\ResellerTier;
use App\Services\Reseller\ResellerApiKeyService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\TestCase;

/**
 * PRD §16 item 73: every Reseller API rejection a known reseller gets is
 * logged from the one render hook, so a later "my order failed yesterday"
 * can be checked. Unauthenticated noise (no key, a wrong key) is not.
 */
class RejectionLogTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Reseller, 1: string} */
    private function reseller(bool $withTier = true): array
    {
        $tier = $withTier
            ? ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => 10, 'is_active' => true, 'sort_order' => 1])
            : null;
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'reseller_tier_id' => $tier?->id, 'is_active' => true]);

        return [$reseller, app(ResellerApiKeyService::class)->issue($reseller, 'Test key')['plainText']];
    }

    private function assertRejectionLogged(Reseller $reseller, string $code, int $status): void
    {
        Log::shouldHaveReceived('warning')->once()->with('Reseller API request rejected', Mockery::on(
            fn (array $context) => $context['reseller_id'] === $reseller->id
                && $context['api_key_id'] === ResellerApiKey::query()->where('reseller_id', $reseller->id)->value('id')
                && $context['error'] === $code
                && $context['status'] === $status
                && str_starts_with($context['path'], 'api/reseller/v1/'),
        ));
    }

    public function test_logs_a_business_rejection_with_the_reseller_and_key(): void
    {
        Log::spy();
        [$reseller, $key] = $this->reseller(withTier: false);

        $this->getJson('/api/reseller/v1/catalog', ['Authorization' => "Bearer {$key}"])->assertStatus(422);

        $this->assertRejectionLogged($reseller, 'NO_TIER_ASSIGNED', 422);
    }

    public function test_logs_a_rejection_raised_by_the_key_middleware(): void
    {
        Log::spy();
        [$reseller, $key] = $this->reseller();
        $reseller->update(['is_active' => false]);

        $this->getJson('/api/reseller/v1/catalog', ['Authorization' => "Bearer {$key}"])->assertForbidden();

        $this->assertRejectionLogged($reseller, 'RESELLER_INACTIVE', 403);
    }

    public function test_logs_a_validation_failure_with_its_details(): void
    {
        Log::spy();
        [$reseller, $key] = $this->reseller();

        $this->postJson('/api/reseller/v1/orders', [], ['Authorization' => "Bearer {$key}"])->assertStatus(422);

        $this->assertRejectionLogged($reseller, 'VALIDATION_FAILED', 422);
        Log::shouldHaveReceived('warning')->with('Reseller API request rejected', Mockery::on(
            fn (array $context) => array_key_exists('product_code', $context['details']),
        ));
    }

    public function test_does_not_log_a_request_without_a_valid_key(): void
    {
        Log::spy();

        $this->getJson('/api/reseller/v1/catalog')->assertUnauthorized();
        $this->getJson('/api/reseller/v1/catalog', ['Authorization' => 'Bearer pgrk_not-real'])->assertUnauthorized();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_does_not_log_a_successful_request(): void
    {
        Log::spy();
        [, $key] = $this->reseller();

        $this->getJson('/api/reseller/v1/balance', ['Authorization' => "Bearer {$key}"])->assertOk();

        Log::shouldNotHaveReceived('warning');
    }

    /** A client hammering past its limit writes one line a minute, not one per request. */
    public function test_logs_rate_limiting_once_per_minute_per_reseller(): void
    {
        RateLimiter::for('reseller-api-reads', fn () => Limit::perMinute(1)->by('test'));
        Log::spy();
        [$reseller, $key] = $this->reseller();
        $headers = ['Authorization' => "Bearer {$key}"];

        $this->getJson('/api/reseller/v1/balance', $headers)->assertOk();
        $this->getJson('/api/reseller/v1/balance', $headers)->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/api/reseller/v1/balance', $headers)->assertStatus(429);

        $this->assertRejectionLogged($reseller, 'RATE_LIMITED', 429);
    }
}
