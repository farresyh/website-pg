<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-029 decision 2: "how the store presents itself to a search
     * engine or tracking script" — deliberately separate from
     * ADR-028's reseller_branding (a different concern, same 1:1-with-
     * Reseller shape convention). SEO-2 (defaults), SEO-3 (template
     * patterns, addendum decision 8's {game_name}/{store_name} tokens),
     * SEO-7 (typed pixel IDs). The original custom_head_script/
     * custom_body_end_script pair from decision 2 was superseded by
     * addendum 2 decision 13's seo_scripts table before build — never
     * added here.
     */
    public function up(): void
    {
        Schema::create('reseller_seo_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->unique()->constrained('resellers')->cascadeOnDelete();
            $table->string('default_meta_title')->nullable();
            $table->text('default_meta_description')->nullable();
            $table->string('default_og_image')->nullable();
            $table->string('meta_title_template')->nullable();
            $table->text('meta_description_template')->nullable();
            $table->string('ga_measurement_id')->nullable();
            $table->string('fb_pixel_id')->nullable();
            $table->string('tiktok_pixel_id')->nullable();
            $table->boolean('schema_organization_enabled')->default(true);
            $table->boolean('schema_product_enabled')->default(true);
            $table->boolean('schema_breadcrumb_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_seo_settings');
    }
};
