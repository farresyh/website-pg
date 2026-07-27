<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * IMG-1/IMG-2 (docs/prd.md §14/§15 backlog) — unblocks real image
     * URLs for Game.image_url / HeroSlide.image_url, both currently
     * plain-paste text inputs. `disk`+`path` (not just a stored `url`)
     * so a future disk swap (local → s3/r2, per the audit's storage
     * plan) never invalidates already-uploaded rows — `url` is derived
     * per-row from whichever disk it actually lives on, not frozen at
     * upload time.
     */
    public function up(): void
    {
        Schema::create('gallery_images', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gallery_images');
    }
};
