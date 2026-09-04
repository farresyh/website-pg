<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-073 decision 3(b): the optional receipt/proof file an admin
     * attaches to a manual `Reseller` (wallet) top-up, for audit. A
     * `ledger_entries` row (`type = wallet_topup`) is the record of
     * truth for the credit itself — this table only holds the attached
     * file, linked back via `ledger_entries.reference_type =
     * 'wallet_topup_receipt'` / `reference_id` (the existing polymorphic
     * pair every other ledger reference already uses, e.g. 'order').
     * Same `Storage`-facade-behind-a-config-disk shape as `GalleryImage`,
     * but on the deliberately private `wallet_receipts_disk`.
     */
    public function up(): void
    {
        Schema::create('wallet_topup_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_topup_receipts');
    }
};
