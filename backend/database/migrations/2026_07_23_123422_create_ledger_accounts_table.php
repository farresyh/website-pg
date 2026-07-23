<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pure lock/mutex anchor — deliberately holds NO balance data.
     * Balance is always SUM(ledger_entries.amount) (ADR-002); this table
     * exists only so a withdrawal can `SELECT ... FOR UPDATE` a row that is
     * guaranteed to already exist (created alongside the owner), avoiding
     * the "zero rows to lock" gap a brand-new owner would otherwise have.
     */
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
