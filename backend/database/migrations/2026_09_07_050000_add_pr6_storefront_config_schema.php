<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-060 PR-6 (planning addendum, 2026-09-07): the schema the
     * affiliate storefront-config editors write to.
     *
     *  - `affiliate_game` — per-brand catalog visibility. A full join
     *    with an explicit `is_visible` (decision 3), not a sparse
     *    disabled-list: absent row = visible (default-on), a row = the
     *    affiliate's explicit choice, which survives a game being
     *    globally deactivated and reactivated.
     *  - `hero_slides.affiliate_id` — nullable FK. Null = the global /
     *    primary slide set (the "null = global" convention, as
     *    `membership_plans`). A brand with zero *active* own slides
     *    falls back to the null set (decision 6 / Q15).
     *  - `hero_slides.image_path` — disk path for an affiliate-uploaded
     *    slide image, so the file can be deleted on replace/remove. Null
     *    for admin's URL-pasted rows (the table is deliberately
     *    dual-natured — cleanup branches on `image_path !== null`).
     *  - `affiliate_branding.logo_path` — disk path, NOT a URL. Deviation
     *    from the 2026-09-05 addendum §1 ("`logo_url` ... the resulting
     *    URL"): a stored absolute URL breaks on the future R2 cutover.
     *    The `AffiliateBranding::logo_url` accessor derives the URL at
     *    read time from `config('filesystems.gallery_disk')`.
     *  - `affiliate_markup_changes` — append-only audit of every
     *    `markup_pct` change (decision 6 / Q17). Mirrors
     *    `affiliate_tier_changes`; a money-affecting self-serve field
     *    gets a trail even though orders already snapshot the effective
     *    rate per ORD-9.
     */
    public function up(): void
    {
        Schema::create('affiliate_game', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->unique(['affiliate_id', 'game_id']);
            // backend/AGENTS.md standalone-index gotcha: `constrained()`
            // has been found not to reliably leave its own FK index on
            // MySQL, and the composite unique above does not cover
            // `game_id` on its own.
            $table->index('affiliate_id', 'affiliate_game_affiliate_id_index');
            $table->index('game_id', 'affiliate_game_game_id_index');
        });

        Schema::table('hero_slides', function (Blueprint $table) {
            $table->foreignId('affiliate_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('image_path')->nullable()->after('image_url');
            $table->index('affiliate_id', 'hero_slides_affiliate_id_index');
        });

        Schema::table('affiliate_branding', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('description');
        });

        Schema::create('affiliate_markup_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            // Null on the very first explicit set.
            $table->decimal('old_pct', 5, 2)->nullable();
            $table->decimal('new_pct', 5, 2);
            // 'portal' (affiliate self-serve) | 'admin' (an admin edit).
            $table->string('source');
            // The acting user's id + a display snapshot — not a hard FK,
            // because the id space differs by source (affiliate_users vs
            // admin_users) and this row must outlive either.
            $table->unsignedBigInteger('changed_by_id')->nullable();
            $table->string('changed_by_label')->nullable();
            // Append-only: created_at only, never updated.
            $table->timestamp('created_at')->nullable();

            $table->index('affiliate_id', 'affiliate_markup_changes_affiliate_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_markup_changes');

        Schema::table('affiliate_branding', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });

        Schema::table('hero_slides', function (Blueprint $table) {
            // MySQL refuses to drop an index a foreign key still needs —
            // drop the FK first, then its standalone index, then the
            // columns (sqlite does not enforce this, so the fast suite
            // never caught the wrong order).
            $table->dropForeign(['affiliate_id']);
            $table->dropIndex('hero_slides_affiliate_id_index');
            $table->dropColumn(['affiliate_id', 'image_path']);
        });

        Schema::dropIfExists('affiliate_game');
    }
};
