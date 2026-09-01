<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-035. Nullable+unique: Path B (storeFromOrder()) never sets
     * this — multiple NULLs are distinct under a unique index, so
     * Path B's rows never collide with each other or with Path A's.
     * Scoped to Path A (store()) only.
     */
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
