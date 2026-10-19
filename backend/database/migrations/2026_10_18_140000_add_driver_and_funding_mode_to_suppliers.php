<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-125 decisions 1 and 18 (+ the 2026-10-18 addendum, decisions 25-26):
 * `driver` names the adapter (`supplier-adapter.{driver}`), so a manual
 * supplier is panel data and the slug becomes a free name for it. Every
 * existing row is an API supplier, backfilled `driver = slug`, which keeps
 * every slug-keyed protocol check (webhook, replay rule) true; an API
 * supplier must keep `slug === driver` (CreateSupplierRequest).
 * `funding_mode` is `prepaid` for every API supplier.
 */
return new class extends Migration
{
    public function up(): void
    {
        // NOT NULL with an empty default, then backfilled, rather than
        // nullable + ->change(): on sqlite ->change() rebuilds the table,
        // which breaks the llm_report_catalog view that reads it. A row
        // stored with '' (a seeder runs WithoutModelEvents, a raw insert)
        // reads as its slug through Supplier::driver().
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('driver', 32)->default('')->after('slug');
            $table->string('funding_mode', 16)->default('prepaid')->after('currency');
        });

        DB::table('suppliers')->update(['driver' => DB::raw('slug')]);
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['driver', 'funding_mode']);
        });
    }
};
