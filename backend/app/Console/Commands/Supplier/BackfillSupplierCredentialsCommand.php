<?php

namespace App\Console\Commands\Supplier;

use App\Models\Supplier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * ADR-046 decision 2/13 — one-time step run before the
 * AppServiceProvider cutover ships: copies the fields
 * GamevionAdapter/DigiflazzAdapter actually need out of
 * config('services.<slug>') (i.e. .env) into that Supplier row's
 * api_config, so the cutover doesn't break a supplier whose row
 * already exists (gamevion, via the sync-command firstOrCreate
 * stopgap) but whose api_config has always been empty.
 *
 * Never creates a Supplier row — a missing row (digiflazz, until a
 * real account/verified credentials exist) is skipped, not
 * fabricated. Safe to re-run: a row with a non-empty api_config is
 * left alone unless --force is passed.
 */
#[Signature('app:backfill-supplier-credentials {--force : overwrite a supplier row that already has a non-empty api_config}')]
#[Description('Copy gamevion/digiflazz .env credentials into their Supplier.api_config row, once, before the adapter binding cuts over to reading from the DB.')]
class BackfillSupplierCredentialsCommand extends Command
{
    /**
     * @var array<string, list<string>>
     */
    private const FIELD_MAP = [
        'gamevion' => ['base_url', 'bearer_token', 'api_key', 'sandbox'],
        'digiflazz' => ['base_url', 'username', 'api_key', 'testing', 'customer_no_separator'],
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $touched = 0;

        foreach (self::FIELD_MAP as $slug => $fields) {
            $supplier = Supplier::query()->where('slug', $slug)->first();

            if ($supplier === null) {
                $this->warn("No Supplier row for '{$slug}' — skipping (this command never creates one).");

                continue;
            }

            if (! empty($supplier->api_config) && ! $force) {
                $this->info("'{$slug}' already has api_config set — skipping (pass --force to overwrite).");

                continue;
            }

            $envConfig = config("services.{$slug}");
            $apiConfig = array_intersect_key($envConfig, array_flip($fields));

            if (blank($apiConfig['api_key'] ?? null)) {
                $this->warn("'{$slug}': .env has no real API key set — backfilling anyway, but this row will still fail a real call until real credentials are entered.");
            }

            $supplier->update(['api_config' => $apiConfig]);
            $touched++;
            $this->info("Backfilled '{$slug}' api_config from .env.");
        }

        return $touched > 0 ? self::SUCCESS : self::FAILURE;
    }
}
