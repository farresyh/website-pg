<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-067 2026-11-07 addendum: a game link is one decision per group, so it
 * moves off the items. Decision 14 sends MOBILE LEGENDS — Umum, the one wrong
 * link, to Indonesia. Decision 15 dismisses the one package that leaves on
 * Malaysia: only its reason changes, it keeps its game, reference and order.
 */
return new class extends Migration
{
    private const UMUM = 'MOBILE LEGENDS — Umum';

    private const MALAYSIA_PACKAGE_REF = 'MLBB_ALL_14_PG1';

    public function up(): void
    {
        Schema::create('supplier_group_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('group_label');
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['supplier_id', 'group_label']);
        });

        DB::table('supplier_group_links')->insertUsing(
            ['supplier_id', 'group_label', 'game_id', 'created_at', 'updated_at'],
            DB::table('supplier_products')
                ->whereNotNull('game_id')
                ->groupBy('supplier_id', 'group_label')
                ->select('supplier_id', 'group_label')
                ->selectRaw('MIN(game_id), ?, ?', [now(), now()]),
        );

        $digiflazz = DB::table('suppliers')->where('slug', 'digiflazz')->value('id');
        $indonesia = DB::table('games')->where('slug', 'mobile-legends-indonesia')->value('id');

        if ($digiflazz !== null && $indonesia !== null) {
            DB::table('supplier_group_links')
                ->where('supplier_id', $digiflazz)
                ->where('group_label', self::UMUM)
                ->update(['game_id' => $indonesia]);
        }

        $this->malaysiaPackage('supplier_sync')->update(['deactivated_reason' => 'admin']);

        $this->underViews(fn () => Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('game_id');
        }));
    }

    public function down(): void
    {
        $this->underViews(fn () => Schema::table('supplier_products', function (Blueprint $table) {
            $table->foreignId('game_id')->nullable()->after('supplier_id')->constrained('games')->nullOnDelete();
        }));

        foreach (DB::table('supplier_group_links')->get() as $link) {
            DB::table('supplier_products')
                ->where('supplier_id', $link->supplier_id)
                ->where('group_label', $link->group_label)
                ->update(['game_id' => $link->game_id]);
        }

        $this->malaysiaPackage('admin')->update(['deactivated_reason' => 'supplier_sync']);

        Schema::dropIfExists('supplier_group_links');
    }

    private function malaysiaPackage(string $reason): Builder
    {
        return DB::table('packages')
            ->where('supplier_package_ref', self::MALAYSIA_PACKAGE_REF)
            ->where('supplier_id', DB::table('suppliers')->where('slug', 'digiflazz')->select('id'))
            ->where('game_id', DB::table('games')->where('slug', 'mobile-legends-malaysia')->select('id'))
            ->where('is_active', false)
            ->where('deactivated_reason', $reason);
    }

    /** sqlite rebuilds the table, which it refuses under a view (2026_11_10_120000). MySQL alters in place. */
    private function underViews(callable $change): void
    {
        $views = DB::getDriverName() === 'sqlite' ? DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'view'") : [];

        foreach ($views as $view) {
            DB::statement("DROP VIEW \"{$view->name}\"");
        }

        $change();

        foreach ($views as $view) {
            DB::statement($view->sql);
        }
    }
};
