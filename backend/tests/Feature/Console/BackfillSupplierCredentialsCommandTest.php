<?php

namespace Tests\Feature\Console;

use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Digiflazz only since ADR-137: Gamevion's config is entered in /middleware. */
class BackfillSupplierCredentialsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function digiflazzEnv(string $apiKey = 'key'): void
    {
        config([
            'services.digiflazz.base_url' => 'https://api.digiflazz.test', 'services.digiflazz.username' => 'user',
            'services.digiflazz.api_key' => $apiKey, 'services.digiflazz.testing' => true, 'services.digiflazz.customer_no_separator' => '|',
        ]);
    }

    public function test_skips_a_supplier_with_no_existing_row(): void
    {
        $this->artisan('app:backfill-supplier-credentials')->assertExitCode(1);

        $this->assertDatabaseMissing('suppliers', ['slug' => 'digiflazz']);
    }

    public function test_backfills_api_config_from_env_config_for_an_existing_empty_row(): void
    {
        $this->digiflazzEnv();
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);

        $this->artisan('app:backfill-supplier-credentials')->assertExitCode(0);

        $this->assertSame(
            ['base_url' => 'https://api.digiflazz.test', 'username' => 'user', 'api_key' => 'key', 'testing' => true, 'customer_no_separator' => '|'],
            $supplier->fresh()->api_config,
        );
    }

    public function test_does_not_overwrite_an_already_configured_row_without_force(): void
    {
        $this->digiflazzEnv('new-key');
        $supplier = Supplier::query()->create([
            'name' => 'Digiflazz', 'slug' => 'digiflazz', 'currency' => 'IDR', 'api_config' => ['api_key' => 'already-set'],
        ]);

        $this->artisan('app:backfill-supplier-credentials')->assertExitCode(1);

        $this->assertSame('already-set', $supplier->fresh()->api_config['api_key']);
    }

    public function test_force_overwrites_an_already_configured_row(): void
    {
        $this->digiflazzEnv('new-key');
        $supplier = Supplier::query()->create([
            'name' => 'Digiflazz', 'slug' => 'digiflazz', 'currency' => 'IDR', 'api_config' => ['api_key' => 'already-set'],
        ]);

        $this->artisan('app:backfill-supplier-credentials --force')->assertExitCode(0);

        $this->assertSame('new-key', $supplier->fresh()->api_config['api_key']);
    }
}
