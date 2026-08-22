<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-029 addendum 2 decision 13: replaces the flat
     * custom_head_script/custom_body_end_script column pair originally
     * proposed in decision 2 — ordered, multiple scripts, reseller-
     * scoped even with today's single reseller (same "build the real
     * shape ahead of a stated future direction" reasoning ADR-028's
     * reseller_branding already used). `reseller_id` nullable: null
     * means a global script, matching decision 13's own wording.
     */
    public function up(): void
    {
        Schema::create('seo_scripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->cascadeOnDelete();
            $table->string('name');
            $table->enum('location', ['head', 'body_end']);
            $table->text('code');
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_scripts');
    }
};
