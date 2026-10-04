<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 63 (2026-10-04): the storefront checkout now applies the same
 * idempotency payload-hash rule the Reseller API introduced (ADR-084
 * PR-1 decision 5), so the column stops being Reseller-API-specific.
 * A metadata-only rename on MySQL 8; no index on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('reseller_api_idempotency_payload_hash', 'idempotency_payload_hash');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('idempotency_payload_hash', 'reseller_api_idempotency_payload_hash');
        });
    }
};
