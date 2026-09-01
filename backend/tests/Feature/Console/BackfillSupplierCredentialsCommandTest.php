<?php

namespace Tests\Feature\Console;

use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillSupplierCredentialsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_a_supplier_with_no_existing_row(): void
    {
        $this->artisan('app:backfill-supplier-credentials')->assertExitCode(1);

        $this->assertDatabaseMissing('suppliers', ['slug' => 'gamevion']);
    }

    public function test_backfills_api_config_from_env_config_for_an_existing_empty_row(): void
    {
        config(['services.gamevion.base_url' => 'https://api.gamevion.test', 'services.gamevion.bearer_token' => 'tok', 'services.gamevion.api_key' => 'key', 'services.gamevion.sandbox' => true]);

        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);

        $this->artisan('app:backfill-supplier-credentials')->assertExitCode(0);

        $this->assertSame(
            ['base_url' => 'https://api.gamevion.test', 'bearer_token' => 'tok', 'api_key' => 'key', 'sandbox' => true],
            $supplier->fresh()->api_config,
        );
    }

    public function test_does_not_overwrite_an_already_configured_row_without_force(): void
    {
        config(['services.gamevion.bearer_token' => 'new-token', 'services.gamevion.api_key' => 'new-key']);

        $supplier = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'currency' => 'MYR',
            'api_config' => ['bearer_token' => 'already-set', 'api_key' => 'already-set'],
        ]);

        $this->artisan('app:backfill-supplier-credentials')->assertExitCode(1);

        $this->assertSame('already-set', $supplier->fresh()->api_config['bearer_token']);
    }

    public function test_force_overwrites_an_already_configured_row(): void
    {
        config(['services.gamevion.base_url' => 'https://api.gamevion.test', 'services.gamevion.bearer_token' => 'new-token', 'services.gamevion.api_key' => 'new-key', 'services.gamevion.sandbox' => true]);

        $supplier = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'currency' => 'MYR',
            'api_config' => ['bearer_token' => 'already-set', 'api_key' => 'already-set'],
        ]);

        $this->artisan('app:backfill-supplier-credentials --force')->assertExitCode(0);

        $this->assertSame('new-token', $supplier->fresh()->api_config['bearer_token']);
    }
}
