<?php

namespace Tests\Feature\Models;

use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_config_round_trips_through_the_encrypted_cast(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'api_config' => [
                'base_url' => 'https://api.gamevion.com',
                'bearer_token' => 'secret-bearer-token',
                'api_key' => 'secret-api-key',
            ],
            'currency' => 'MYR',
        ]);

        $reloaded = Supplier::query()->findOrFail($supplier->id);

        $this->assertSame('secret-bearer-token', $reloaded->api_config['bearer_token']);
    }

    /**
     * SUPP-5: credentials are encrypted at rest — the raw DB column
     * must never contain the plaintext secret, only ciphertext.
     */
    public function test_api_config_is_actually_encrypted_in_the_database(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'api_config' => ['bearer_token' => 'super-secret-token-value'],
            'currency' => 'MYR',
        ]);

        $rawValue = DB::table('suppliers')->where('id', $supplier->id)->value('api_config');

        $this->assertStringNotContainsString('super-secret-token-value', $rawValue);
    }

    /**
     * SUPP-5: never returned in full via any API response — defense
     * in depth so a future controller can't accidentally leak it via
     * a plain ->toArray()/->toJson() call.
     */
    public function test_api_config_is_hidden_from_array_and_json_serialization(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'api_config' => ['bearer_token' => 'secret-value'],
            'currency' => 'MYR',
        ]);

        $this->assertArrayNotHasKey('api_config', $supplier->toArray());
        $this->assertStringNotContainsString('secret-value', $supplier->toJson());
    }

    public function test_is_active_defaults_to_true(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Gamevion',
            'slug' => 'gamevion',
            'api_config' => [],
            'currency' => 'MYR',
        ]);

        // The DB column default isn't reflected on the in-memory
        // instance create() returns — only a real row read shows it.
        $reloaded = Supplier::query()->findOrFail($supplier->id);

        $this->assertTrue($reloaded->is_active);
    }
}
