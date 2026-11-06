<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-119 2026-11-05 addendum, decision 1: the schema itself refuses to
 * delete a used game or package. Before this, deleting a game cascaded to
 * its packages and nulled its orders' links, past the guard that lived
 * only in PackageController::destroy(). Rows already null stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->underViews(function () {
            Schema::table('packages', function (Blueprint $table) {
                $table->dropForeign(['game_id']);
                $table->foreign('game_id')->references('id')->on('games')->restrictOnDelete();
            });

            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['game_id']);
                $table->dropForeign(['package_id']);
                $table->foreign('game_id')->references('id')->on('games')->restrictOnDelete();
                $table->foreign('package_id')->references('id')->on('packages')->restrictOnDelete();
            });
        });
    }

    public function down(): void
    {
        $this->underViews(function () {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['game_id']);
                $table->dropForeign(['package_id']);
                $table->foreign('game_id')->references('id')->on('games')->nullOnDelete();
                $table->foreign('package_id')->references('id')->on('packages')->nullOnDelete();
            });

            Schema::table('packages', function (Blueprint $table) {
                $table->dropForeign(['game_id']);
                $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            });
        });
    }

    /**
     * sqlite changes a foreign key by rebuilding the table, which it refuses
     * under a view that reads it (the llm_report_* views). Re-create them
     * from their own stored SQL, as 2026_10_08_140000 does. MySQL alters in place.
     */
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
