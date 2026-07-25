<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Reseller;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        AdminUser::factory()->superAdmin()->create([
            'name' => 'Test Super Admin',
            'email' => 'test@example.com',
        ]);

        // PRD §8: exactly one Reseller row for the platform owner's own
        // internal storefront (markup_pct=0) — see the
        // create_resellers_table migration's doc comment for why this
        // isn't a separate "no reseller yet" special case.
        Reseller::query()->firstOrCreate(
            ['business_name' => 'Platform Owner'],
            ['markup_pct' => 0, 'status' => 'active'],
        );

        $this->call(PaymentMethodSeeder::class);
    }
}
