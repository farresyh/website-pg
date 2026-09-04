<?php

namespace Tests\Feature\Http\Controllers\ResellerApi;

use App\Models\Reseller;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\ResellerApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-074 decision 3: GET /api/reseller/v1/balance. */
class BalanceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_callers_own_wallet_balance(): void
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        $ledger = app(LedgerService::class);
        $ledger->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        $ledger->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 5000, 'wallet_topup');
        $key = app(ResellerApiKeyService::class)->issue($reseller, 'Test key')['plainText'];

        $response = $this->getJson('/api/reseller/v1/balance', ['Authorization' => "Bearer {$key}"]);

        $response->assertOk();
        $response->assertJson(['balance_sen' => 5000]);
    }

    public function test_requires_a_valid_api_key(): void
    {
        $this->getJson('/api/reseller/v1/balance')->assertUnauthorized();
    }
}
