<?php

namespace Tests\Unit\Services\Payment\Chip;

use App\Models\PaymentGateway;
use App\Services\Payment\Chip\ChipCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-110 PR-C — the single source of truth for CHIP credential
 * resolution, shared by the real `payment-gateway.chip` container
 * binding and the two manual smoke-test commands (previously each
 * read `config('services.chip')` directly, which would have silently
 * broken once `.env`'s CHIP_SECRET_KEY/CHIP_BRAND_ID are removed).
 */
class ChipCredentialResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.chip.secret_key' => 'sk_env',
            'services.chip.brand_id' => 'brand-env',
            'services.chip.base_url' => 'https://gate.chip-in.asia/api/v1',
        ]);
    }

    public function test_falls_back_to_env_config_when_no_db_row_exists(): void
    {
        $credentials = (new ChipCredentialResolver)->resolve();

        $this->assertSame('sk_env', $credentials['secret_key']);
        $this->assertSame('brand-env', $credentials['brand_id']);
        $this->assertSame('https://gate.chip-in.asia/api/v1', $credentials['base_url']);
    }

    public function test_prefers_the_db_row_over_env_config(): void
    {
        PaymentGateway::query()->create([
            'gateway_key' => 'chip',
            'api_config' => ['secret_key' => 'sk_db', 'brand_id' => 'brand-db', 'base_url' => 'https://custom.example/api'],
        ]);

        $credentials = (new ChipCredentialResolver)->resolve();

        $this->assertSame('sk_db', $credentials['secret_key']);
        $this->assertSame('brand-db', $credentials['brand_id']);
        $this->assertSame('https://custom.example/api', $credentials['base_url']);
    }

    public function test_falls_back_per_field_when_the_db_row_is_missing_a_key(): void
    {
        PaymentGateway::query()->create([
            'gateway_key' => 'chip',
            'api_config' => ['secret_key' => 'sk_db'],
        ]);

        $credentials = (new ChipCredentialResolver)->resolve();

        $this->assertSame('sk_db', $credentials['secret_key']);
        $this->assertSame('brand-env', $credentials['brand_id']);
        $this->assertSame('https://gate.chip-in.asia/api/v1', $credentials['base_url']);
    }
}
