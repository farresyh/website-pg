<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-126 PR 2a (2026-10-23 addendum, decisions 2, 15, 16):
 *  - `games.is_joki`: the game is in no catalog on any brand; its page and
 *    checkout are joki's own.
 *  - `suppliers.joki_role`: `pool` for the one "waiting for a penjoki"
 *    supplier, `penjoki` for each player; null for every other supplier.
 *  - `joki_assignments`: one audit row per Assign.
 *  - the pool supplier itself. It is never edited from the supplier
 *    screens; the joki switch is its only switch (F5).
 *  - each assignment gets its own group post and login link: the post's
 *    dedupe key gains `:assign:<id>` (so `dedupe_key` widens to 191), and
 *    a link remembers the assignment it was minted for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->boolean('is_joki')->default(false)->after('member_pricing_enabled');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('joki_role', 16)->nullable()->after('funding_mode')->index();
        });

        Schema::create('joki_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_supplier_id')->constrained('suppliers');
            $table->foreignId('to_supplier_id')->constrained('suppliers');
            $table->string('assigned_by');
            $table->timestamp('created_at')->useCurrent();
            $table->index('order_id');
        });

        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->string('dedupe_key', 191)->change();
        });

        Schema::table('supplier_login_links', function (Blueprint $table) {
            $table->foreignId('joki_assignment_id')->nullable()->after('reference_number')->constrained('joki_assignments')->nullOnDelete();
        });

        DB::table('suppliers')->insert([
            'name' => 'Joki – waiting for a penjoki',
            'slug' => 'joki-pool',
            'driver' => 'manual',
            'funding_mode' => 'pay_after',
            'joki_role' => 'pool',
            'currency' => 'MYR',
            // The model's `encrypted:array` cast, written by hand.
            'api_config' => Crypt::encryptString('[]'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // The pool can be removed only while nothing points at it; once a
        // joki package or order exists it stays, switched off, as an
        // ordinary manual supplier.
        $pool = DB::table('suppliers')->where('slug', 'joki-pool')->where('joki_role', 'pool');
        $poolId = (clone $pool)->value('id');
        $referenced = $poolId !== null && (
            DB::table('packages')->where('supplier_id', $poolId)->exists()
            || DB::table('orders')->where('supplier_id', $poolId)->exists()
            || DB::table('joki_assignments')->where('from_supplier_id', $poolId)->exists()
        );
        $referenced ? $pool->update(['is_active' => false]) : $pool->delete();

        Schema::table('supplier_login_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('joki_assignment_id');
        });

        Schema::dropIfExists('joki_assignments');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex(['joki_role']);
            $table->dropColumn('joki_role');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('is_joki');
        });
    }
};
