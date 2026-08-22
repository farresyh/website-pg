<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-028 addendum decision 10: split out of reseller_branding —
     * footer text + the three legal-page contents (all sanitized HTML,
     * decision 12) + Footer Games (decision 14, an ordered JSON array
     * of Game ids — order is display order, same reasoning
     * hero_slides.sort_order already established). 1:1 with Reseller,
     * same shape convention as reseller_branding.
     */
    public function up(): void
    {
        Schema::create('reseller_footer_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->unique()->constrained('resellers')->cascadeOnDelete();
            $table->text('footer_text')->nullable();
            $table->text('terms_content')->nullable();
            $table->text('privacy_content')->nullable();
            $table->text('about_us_content')->nullable();
            $table->json('footer_game_ids')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_footer_settings');
    }
};
