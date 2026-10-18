<?php

namespace Tests\Feature;

use App\Models\Reseller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-125 decision 11 / 2026-10-19 addendum: prod's reseller groups (2 on
 * 2026-10-19) move into `whatsapp_groups` as owner_type = reseller with
 * their ids and state kept, and the pending list survives the rename.
 */
class Adr125WhatsAppGroupsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_groups_and_pending_links_survive_the_move_both_ways(): void
    {
        $migration = require database_path('migrations/2026_10_19_100000_create_whatsapp_groups_table.php');
        $migration->down();

        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        DB::table('reseller_whatsapp_groups')->insert([
            ['id' => 7, 'reseller_id' => $reseller->id, 'whatsapp_group_id' => 'a@g.us', 'is_active' => true, 'created_at' => '2026-09-05 10:00:00', 'updated_at' => '2026-09-05 10:00:00'],
            ['id' => 9, 'reseller_id' => $reseller->id, 'whatsapp_group_id' => 'b@g.us', 'is_active' => false, 'created_at' => '2026-09-06 10:00:00', 'updated_at' => '2026-09-07 10:00:00'],
        ]);
        DB::table('reseller_whatsapp_pending_links')->insert(['whatsapp_group_id' => 'p@g.us', 'last_message_at' => now()]);

        $migration->up();

        $rows = DB::table('whatsapp_groups')->orderBy('id')->get();
        $this->assertSame([7, 9], $rows->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['reseller', 'reseller'], $rows->pluck('owner_type')->all());
        $this->assertSame([$reseller->id, $reseller->id], $rows->pluck('owner_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([1, 0], $rows->pluck('is_active')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('2026-09-07 10:00:00', $rows[1]->updated_at);
        $this->assertSame('p@g.us', DB::table('whatsapp_pending_links')->value('whatsapp_group_id'));
        $this->assertSame(1, $reseller->whatsAppGroups()->where('is_active', true)->count());

        $migration->down();
        $this->assertSame(2, DB::table('reseller_whatsapp_groups')->count());
        $migration->up();
    }
}
