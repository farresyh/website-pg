<?php

namespace Tests\Feature\Database;

use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** ADR-067 2026-11-07 addendum, decisions 14 and 15. */
class MoveGameLinkToSupplierGroupsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'MLBB_ALL_14_PG1';

    private object $migration;

    private Supplier $digiflazz;

    private Game $malaysia;

    private Game $indonesia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migration = require database_path('migrations/2026_11_12_120000_move_game_link_to_supplier_groups.php');
        $this->migration->down();
        $this->digiflazz = Supplier::query()->create(['name' => 'DigiFlazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $this->malaysia = Game::query()->create(['name' => 'Mobile Legends Malaysia', 'slug' => 'mobile-legends-malaysia']);
        $this->indonesia = Game::query()->create(['name' => 'Mobile Legends Indonesia', 'slug' => 'mobile-legends-indonesia']);
    }

    private function row(string $ref, string $group, ?int $gameId): void
    {
        DB::table('supplier_products')->insert([
            'supplier_id' => $this->digiflazz->id, 'game_id' => $gameId, 'external_ref' => $ref, 'name' => $ref,
            'group_label' => $group, 'price_sen' => 100, 'status_raw' => 'active', 'last_synced_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function package(array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $this->malaysia->id, 'name' => '14 Diamonds', 'cost_price' => 90, 'standard_selling_price' => 100,
            'is_active' => false, 'deactivated_reason' => 'supplier_sync', 'deactivated_at' => '2026-09-05 19:30:18',
            'supplier_id' => $this->digiflazz->id, 'supplier_package_ref' => self::REF,
        ], $overrides));
    }

    private function linkOf(string $group): ?int
    {
        return DB::table('supplier_group_links')->where('supplier_id', $this->digiflazz->id)->where('group_label', $group)->value('game_id');
    }

    public function test_a_half_linked_group_becomes_one_link_and_an_unlinked_group_stays_unlinked(): void
    {
        $this->row('MLBB_TH_1', 'MOBILE LEGENDS — Thailand', $this->indonesia->id);
        $this->row('MLBB_TH_2', 'MOBILE LEGENDS — Thailand', null);
        $this->row('X_1', 'Never Linked', null);

        $this->migration->up();

        $this->assertSame($this->indonesia->id, $this->linkOf('MOBILE LEGENDS — Thailand'));
        $this->assertNull($this->linkOf('Never Linked'));
        $this->assertFalse(Schema::hasColumn('supplier_products', 'game_id'));
    }

    public function test_umum_goes_to_indonesia_and_package_516_is_dismissed_and_otherwise_untouched(): void
    {
        $this->row(self::REF, 'MOBILE LEGENDS — Umum', $this->malaysia->id);
        $this->row('MLBB_ALL_113_PG1', 'MOBILE LEGENDS — Umum', null);
        $package = $this->package();
        $order = Order::factory()->create(['package_id' => $package->id, 'game_id' => $this->malaysia->id]);
        $before = DB::table('orders')->where('id', $order->id)->first();

        $this->migration->up();

        $this->assertSame($this->indonesia->id, $this->linkOf('MOBILE LEGENDS — Umum'));
        $package->refresh();
        $this->assertSame('admin', $package->deactivated_reason);
        $this->assertFalse($package->is_active);
        $this->assertSame($this->malaysia->id, $package->game_id);
        $this->assertSame(self::REF, $package->supplier_package_ref);
        $this->assertTrue(Carbon::parse('2026-09-05 19:30:18')->equalTo($package->deactivated_at));
        $this->assertEquals($before, DB::table('orders')->where('id', $order->id)->first());
    }

    public function test_package_516_is_left_alone_if_it_is_no_longer_off_for_the_supplier(): void
    {
        $this->row(self::REF, 'MOBILE LEGENDS — Umum', $this->malaysia->id);
        $on = $this->package(['is_active' => true, 'deactivated_reason' => null, 'deactivated_at' => null]);

        $this->migration->up();

        $this->assertTrue($on->refresh()->is_active);
        $this->assertNull($on->deactivated_reason);
    }

    public function test_a_database_without_these_rows_migrates_cleanly(): void
    {
        DB::table('games')->whereIn('id', [$this->malaysia->id, $this->indonesia->id])->delete();
        DB::table('suppliers')->where('id', $this->digiflazz->id)->delete();

        $this->migration->up();

        $this->assertSame(0, DB::table('supplier_group_links')->count());
    }

    public function test_rollback_restores_the_item_links_and_the_reason(): void
    {
        $this->row(self::REF, 'MOBILE LEGENDS — Umum', $this->malaysia->id);
        $this->row('MLBB_TH_1', 'MOBILE LEGENDS — Thailand', $this->indonesia->id);
        $package = $this->package();

        $this->migration->up();
        $this->migration->down();

        $this->assertSame($this->indonesia->id, DB::table('supplier_products')->where('external_ref', 'MLBB_TH_1')->value('game_id'));
        $this->assertSame('supplier_sync', $package->refresh()->deactivated_reason);
        $this->assertFalse(Schema::hasTable('supplier_group_links'));

        $this->migration->up();
    }
}
