<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-028 decision 2 (as narrowed by the 2026-08-22 addendum
     * decision 10): store identity/contact fields only — footer text
     * and legal content moved to reseller_footer_settings. 1:1 with
     * Reseller, its own table rather than columns on Reseller (decision
     * 2's own "distinct concern" reasoning), so a real second reseller
     * gets working branding with no later migration.
     */
    public function up(): void
    {
        Schema::create('reseller_branding', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->unique()->constrained('resellers')->cascadeOnDelete();
            $table->string('store_name');
            $table->text('description')->nullable();
            $table->string('support_email')->nullable();
            $table->string('support_phone')->nullable();
            $table->string('telegram_contact_link')->nullable();
            $table->json('social_links')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_branding');
    }
};
