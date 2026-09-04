<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-072 PR-A: pure rename, zero behaviour change. The existing
 * whitelabel-storefront-partner entity (`Reseller`/`resellers` — branding,
 * custom domain, subscription tiers, earnings ledger, portal login) is
 * renamed to `Affiliate`/`affiliates`, vacating the `Reseller` name for a
 * brand-new prepaid-wallet entity built in a later PR (ADR-073..075,
 * PR-B onward). This migration is the schema half of that rename; every
 * historical migration above it is left untouched (an immutable record of
 * what actually ran) — every table/column here was created with the old
 * `reseller`-prefixed name by one of those.
 *
 * Order of operations per renamed FK column: drop the existing FK/index
 * (computed from the table's CURRENT — i.e. still-old — name, since a
 * table rename does not rename its constraints' stored names) → rename
 * the table (if this table is one of the ones being renamed) → rename the
 * column → re-add the FK/index with an explicit, clean, new-name-prefixed
 * identifier. FK columns that are NOT being renamed (`admin_user_id`,
 * `from_tier_id`/`to_tier_id`, `personal_access_token_id`) are left alone
 * entirely — MySQL/SQLite both transparently repoint a FK's target at the
 * new table name when the referenced table is renamed via
 * `Schema::rename()`, so those constraints keep working under their
 * original (now cosmetically stale) names. Renaming every such cosmetic
 * identifier for full consistency was considered and deliberately left
 * out of this PR's scope (see the ADR-072 build addendum) — it's a
 * non-functional nice-to-have, not part of "pure rename, zero behaviour
 * change".
 *
 * `reseller_impersonation_sessions_personal_access_token_id_foreign` is
 * exactly 64 characters today (MySQL's identifier limit, AGENTS.md's own
 * documented gotcha) — left untouched here specifically because a naive
 * re-creation under the new `affiliate_impersonation_sessions_` prefix
 * would push it to 65 and fail on real MySQL; not renaming it is the
 * correct call, not an oversight.
 *
 * Also updates the three `owner_type` polymorphic-lookup tables
 * (`ledger_accounts`, `ledger_entries`, `withdrawals`) — real, currently-
 * persisted `'reseller'` string rows are data, not schema, but
 * `LedgerOwnerType`'s enum case value is renamed to `'affiliate'` in this
 * same PR (app/Services/Ledger/LedgerOwnerType.php), so every existing row
 * left at the old string would become unreadable
 * (`LedgerOwnerType::coerce()` throws on an unknown value) the moment this
 * ships — this is the money-critical part of an otherwise purely
 * structural migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // orders.reseller_id is a special case: migration
        // 2026_08_30_110000_add_reseller_id_index_and_backfill_orders only
        // added a standalone `orders_reseller_id_index` when nothing
        // already covered the column — and on real MySQL (confirmed via
        // `SHOW INDEX FROM orders`), the FK added earlier
        // (2026_07_25_150100) already left its own auto-created
        // `orders_reseller_id_foreign` covering index, so that explicit
        // index was never actually created there; SQLite never auto-
        // indexes FKs, so on the sqlite test DB it WAS created. Dropping a
        // hardcoded `orders_reseller_id_index` name would therefore fail
        // on production MySQL ("check that column/key exists"). Resolve
        // the real index name dynamically instead, mirroring that
        // migration's own defensive "only if not already covered" check.
        $ordersResellerIdIndex = collect(Schema::getIndexes('orders'))
            ->first(fn (array $index) => $index['columns'] === ['reseller_id'] && ! $index['unique']);

        // ---- 1. Drop FKs/indexes that are about to be affected, while
        // every table/column still has its original name so Laravel's
        // auto-generated constraint names resolve correctly. ----

        Schema::table('orders', function (Blueprint $table) use ($ordersResellerIdIndex) {
            $table->dropForeign(['reseller_id']);

            // Only drop a standalone index if the FK constraint just
            // dropped wasn't the sole thing backing it (i.e. a genuinely
            // separate index — the sqlite-test-DB case).
            if ($ordersResellerIdIndex !== null && $ordersResellerIdIndex['name'] !== 'orders_reseller_id_foreign') {
                $table->dropIndex($ordersResellerIdIndex['name']);
            }
        });

        Schema::table('reseller_branding', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropUnique(['reseller_id']);
        });

        Schema::table('reseller_footer_settings', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropUnique(['reseller_id']);
        });

        Schema::table('reseller_seo_settings', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropUnique(['reseller_id']);
        });

        Schema::table('seo_scripts', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
        });

        Schema::table('redirects', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropUnique(['reseller_id', 'from_path']);
        });

        Schema::table('reseller_users', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropIndex('reseller_users_reseller_id_index');
        });

        Schema::table('reseller_subscriptions', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropUnique(['reseller_id']);
            $table->dropForeign(['reseller_membership_tier_id']);
            $table->dropIndex('reseller_subs_tier_id_index');
        });

        Schema::table('reseller_tier_changes', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropIndex('reseller_tier_changes_reseller_id_index');
        });

        Schema::table('reseller_impersonation_sessions', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropIndex('reseller_impersonation_sessions_reseller_id_index');
            $table->dropForeign(['reseller_user_id']);
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropUnique(['reseller_id', 'email']);
        });

        Schema::table('membership_otp_codes', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropIndex(['reseller_id', 'email']);
        });

        Schema::table('membership_checkout_attempts', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropIndex(['reseller_id', 'email']);
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropForeign(['reseller_user_id']);
        });

        // ---- 2. Rename tables. ----

        Schema::rename('resellers', 'affiliates');
        Schema::rename('reseller_branding', 'affiliate_branding');
        Schema::rename('reseller_footer_settings', 'affiliate_footer_settings');
        Schema::rename('reseller_seo_settings', 'affiliate_seo_settings');
        Schema::rename('reseller_membership_tiers', 'affiliate_membership_tiers');
        Schema::rename('reseller_subscriptions', 'affiliate_subscriptions');
        Schema::rename('reseller_tier_changes', 'affiliate_tier_changes');
        Schema::rename('reseller_impersonation_sessions', 'affiliate_impersonation_sessions');
        Schema::rename('reseller_users', 'affiliate_users');
        Schema::rename('reseller_password_reset_tokens', 'affiliate_password_reset_tokens');

        // ---- 3. Rename FK/tenant columns. ----

        // orders.reseller_markup_pct/reseller_profit are plain snapshot
        // money columns (no FK/index), but every app call site was
        // renamed to affiliate_markup_pct/affiliate_profit in this same
        // PR (Order::$fillable, OrderFulfillmentService::creditProfit(),
        // CheckoutService, OrderResendService) — they're renamed here
        // alongside affiliate_id so the schema stays in sync with the code.
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('reseller_id', 'affiliate_id');
            $table->renameColumn('reseller_markup_pct', 'affiliate_markup_pct');
            $table->renameColumn('reseller_profit', 'affiliate_profit');
        });

        Schema::table('affiliate_branding', function (Blueprint $table) {
            $table->renameColumn('reseller_id', 'affiliate_id');
        });

        Schema::table('affiliate_footer_settings', function (Blueprint $table) {
            $table->renameColumn('reseller_id', 'affiliate_id');
        });

        Schema::table('affiliate_seo_settings', function (Blueprint $table) {
            $table->renameColumn('reseller_id', 'affiliate_id');
        });

        Schema::table('seo_scripts', function (Blueprint $table) {
            $table->renameColumn('reseller_id', 'affiliate_id');
        });

        Schema::table('redirects', function (Blueprint $table) {
            $table->renameColumn('reseller_id', 'affiliate_id');
        });

        Schema::table('affiliate_users', function (Blueprint $table) {
            $table->renameColumn('reseller_id', 'affiliate_id');
        });

        Schema::table('affiliate_subscriptions', function (Blueprint $table) {
            $table->renameColumn('reseller_id', 'affiliate_id');
            $table->renameColumn('reseller_membership_tier_id', 'affiliate_membership_tier_id');
        });

        Schema::table('affiliate_tier_changes', function (Blueprint $table) {
            $table->renameColumn('reseller_id', 'affiliate_id');
        });

        Schema::table('affiliate_impersonation_sessions', function (Blueprint $table) {
            $table->renameColumn('reseller_id', 'affiliate_id');
            $table->renameColumn('reseller_user_id', 'affiliate_user_id');
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->renameColumn('reseller_id', 'affiliate_id');
        });

        Schema::table('membership_otp_codes', function (Blueprint $table) {
            $table->renameColumn('reseller_id', 'affiliate_id');
        });

        Schema::table('membership_checkout_attempts', function (Blueprint $table) {
            $table->renameColumn('reseller_id', 'affiliate_id');
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->renameColumn('reseller_user_id', 'affiliate_user_id');
        });

        // ---- 4. Re-add FKs/indexes under the new names. ----

        Schema::table('orders', function (Blueprint $table) use ($ordersResellerIdIndex) {
            // Only add a standalone index back when one genuinely existed
            // before (the sqlite-test-DB case) — on real MySQL, re-adding
            // the FK below leaves its own auto-created supporting index,
            // exactly mirroring the pre-rename production state.
            if ($ordersResellerIdIndex !== null && $ordersResellerIdIndex['name'] !== 'orders_reseller_id_foreign') {
                $table->index('affiliate_id', 'orders_affiliate_id_index');
            }

            $table->foreign('affiliate_id', 'orders_affiliate_id_foreign')
                ->references('id')->on('affiliates')->restrictOnDelete();
        });

        Schema::table('affiliate_branding', function (Blueprint $table) {
            $table->unique('affiliate_id', 'affiliate_branding_affiliate_id_unique');
            $table->foreign('affiliate_id', 'affiliate_branding_affiliate_id_foreign')
                ->references('id')->on('affiliates')->cascadeOnDelete();
        });

        Schema::table('affiliate_footer_settings', function (Blueprint $table) {
            $table->unique('affiliate_id', 'affiliate_footer_settings_affiliate_id_unique');
            $table->foreign('affiliate_id', 'affiliate_footer_settings_affiliate_id_foreign')
                ->references('id')->on('affiliates')->cascadeOnDelete();
        });

        Schema::table('affiliate_seo_settings', function (Blueprint $table) {
            $table->unique('affiliate_id', 'affiliate_seo_settings_affiliate_id_unique');
            $table->foreign('affiliate_id', 'affiliate_seo_settings_affiliate_id_foreign')
                ->references('id')->on('affiliates')->cascadeOnDelete();
        });

        Schema::table('seo_scripts', function (Blueprint $table) {
            $table->foreign('affiliate_id', 'seo_scripts_affiliate_id_foreign')
                ->references('id')->on('affiliates')->cascadeOnDelete();
        });

        Schema::table('redirects', function (Blueprint $table) {
            $table->unique(['affiliate_id', 'from_path'], 'redirects_affiliate_id_from_path_unique');
            $table->foreign('affiliate_id', 'redirects_affiliate_id_foreign')
                ->references('id')->on('affiliates')->cascadeOnDelete();
        });

        Schema::table('affiliate_users', function (Blueprint $table) {
            $table->index('affiliate_id', 'affiliate_users_affiliate_id_index');
            $table->foreign('affiliate_id', 'affiliate_users_affiliate_id_foreign')
                ->references('id')->on('affiliates')->cascadeOnDelete();
        });

        Schema::table('affiliate_subscriptions', function (Blueprint $table) {
            $table->unique('affiliate_id', 'affiliate_subscriptions_affiliate_id_unique');
            $table->foreign('affiliate_id', 'affiliate_subscriptions_affiliate_id_foreign')
                ->references('id')->on('affiliates')->cascadeOnDelete();
            $table->foreign('affiliate_membership_tier_id', 'affiliate_subs_tier_id_foreign')
                ->references('id')->on('affiliate_membership_tiers')->restrictOnDelete();
            $table->index('affiliate_membership_tier_id', 'affiliate_subs_tier_id_index');
        });

        Schema::table('affiliate_tier_changes', function (Blueprint $table) {
            $table->index('affiliate_id', 'affiliate_tier_changes_affiliate_id_index');
            $table->foreign('affiliate_id', 'affiliate_tier_changes_affiliate_id_foreign')
                ->references('id')->on('affiliates')->nullOnDelete();
        });

        Schema::table('affiliate_impersonation_sessions', function (Blueprint $table) {
            $table->index('affiliate_id', 'affiliate_impersonation_sessions_affiliate_id_index');
            $table->foreign('affiliate_id', 'affiliate_impersonation_sessions_affiliate_id_foreign')
                ->references('id')->on('affiliates')->cascadeOnDelete();
            $table->foreign('affiliate_user_id', 'affiliate_impersonation_sessions_affiliate_user_id_foreign')
                ->references('id')->on('affiliate_users')->nullOnDelete();
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->unique(['affiliate_id', 'email'], 'memberships_affiliate_id_email_unique');
            $table->foreign('affiliate_id', 'memberships_affiliate_id_foreign')
                ->references('id')->on('affiliates')->cascadeOnDelete();
        });

        Schema::table('membership_otp_codes', function (Blueprint $table) {
            $table->index(['affiliate_id', 'email'], 'membership_otp_codes_affiliate_id_email_index');
            $table->foreign('affiliate_id', 'membership_otp_codes_affiliate_id_foreign')
                ->references('id')->on('affiliates')->cascadeOnDelete();
        });

        Schema::table('membership_checkout_attempts', function (Blueprint $table) {
            $table->index(['affiliate_id', 'email'], 'membership_checkout_attempts_affiliate_id_email_index');
            $table->foreign('affiliate_id', 'membership_checkout_attempts_affiliate_id_foreign')
                ->references('id')->on('affiliates')->cascadeOnDelete();
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->foreign('affiliate_user_id', 'withdrawals_affiliate_user_id_foreign')
                ->references('id')->on('affiliate_users')->nullOnDelete();
        });

        // ---- 5. Data: the polymorphic owner_type literal. ----

        DB::table('ledger_accounts')->where('owner_type', 'reseller')->update(['owner_type' => 'affiliate']);
        DB::table('ledger_entries')->where('owner_type', 'reseller')->update(['owner_type' => 'affiliate']);
        DB::table('withdrawals')->where('owner_type', 'reseller')->update(['owner_type' => 'affiliate']);
    }

    public function down(): void
    {
        DB::table('ledger_accounts')->where('owner_type', 'affiliate')->update(['owner_type' => 'reseller']);
        DB::table('ledger_entries')->where('owner_type', 'affiliate')->update(['owner_type' => 'reseller']);
        DB::table('withdrawals')->where('owner_type', 'affiliate')->update(['owner_type' => 'reseller']);

        // Mirrors up()'s own dynamic detection — see its comment for why
        // this can't be a hardcoded index name.
        $ordersAffiliateIdIndex = collect(Schema::getIndexes('orders'))
            ->first(fn (array $index) => $index['columns'] === ['affiliate_id'] && ! $index['unique']);

        Schema::table('orders', function (Blueprint $table) use ($ordersAffiliateIdIndex) {
            $table->dropForeign('orders_affiliate_id_foreign');

            if ($ordersAffiliateIdIndex !== null && $ordersAffiliateIdIndex['name'] !== 'orders_affiliate_id_foreign') {
                $table->dropIndex($ordersAffiliateIdIndex['name']);
            }
        });

        Schema::table('affiliate_branding', function (Blueprint $table) {
            $table->dropForeign('affiliate_branding_affiliate_id_foreign');
            $table->dropUnique('affiliate_branding_affiliate_id_unique');
        });

        Schema::table('affiliate_footer_settings', function (Blueprint $table) {
            $table->dropForeign('affiliate_footer_settings_affiliate_id_foreign');
            $table->dropUnique('affiliate_footer_settings_affiliate_id_unique');
        });

        Schema::table('affiliate_seo_settings', function (Blueprint $table) {
            $table->dropForeign('affiliate_seo_settings_affiliate_id_foreign');
            $table->dropUnique('affiliate_seo_settings_affiliate_id_unique');
        });

        Schema::table('seo_scripts', function (Blueprint $table) {
            $table->dropForeign('seo_scripts_affiliate_id_foreign');
        });

        Schema::table('redirects', function (Blueprint $table) {
            $table->dropForeign('redirects_affiliate_id_foreign');
            $table->dropUnique('redirects_affiliate_id_from_path_unique');
        });

        Schema::table('affiliate_users', function (Blueprint $table) {
            $table->dropForeign('affiliate_users_affiliate_id_foreign');
            $table->dropIndex('affiliate_users_affiliate_id_index');
        });

        Schema::table('affiliate_subscriptions', function (Blueprint $table) {
            $table->dropForeign('affiliate_subscriptions_affiliate_id_foreign');
            $table->dropUnique('affiliate_subscriptions_affiliate_id_unique');
            $table->dropForeign('affiliate_subs_tier_id_foreign');
            $table->dropIndex('affiliate_subs_tier_id_index');
        });

        Schema::table('affiliate_tier_changes', function (Blueprint $table) {
            $table->dropForeign('affiliate_tier_changes_affiliate_id_foreign');
            $table->dropIndex('affiliate_tier_changes_affiliate_id_index');
        });

        Schema::table('affiliate_impersonation_sessions', function (Blueprint $table) {
            $table->dropForeign('affiliate_impersonation_sessions_affiliate_id_foreign');
            $table->dropIndex('affiliate_impersonation_sessions_affiliate_id_index');
            $table->dropForeign('affiliate_impersonation_sessions_affiliate_user_id_foreign');
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign('memberships_affiliate_id_foreign');
            $table->dropUnique('memberships_affiliate_id_email_unique');
        });

        Schema::table('membership_otp_codes', function (Blueprint $table) {
            $table->dropForeign('membership_otp_codes_affiliate_id_foreign');
            $table->dropIndex('membership_otp_codes_affiliate_id_email_index');
        });

        Schema::table('membership_checkout_attempts', function (Blueprint $table) {
            $table->dropForeign('membership_checkout_attempts_affiliate_id_foreign');
            $table->dropIndex('membership_checkout_attempts_affiliate_id_email_index');
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropForeign('withdrawals_affiliate_user_id_foreign');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('affiliate_id', 'reseller_id');
            $table->renameColumn('affiliate_markup_pct', 'reseller_markup_pct');
            $table->renameColumn('affiliate_profit', 'reseller_profit');
        });

        Schema::table('affiliate_branding', function (Blueprint $table) {
            $table->renameColumn('affiliate_id', 'reseller_id');
        });

        Schema::table('affiliate_footer_settings', function (Blueprint $table) {
            $table->renameColumn('affiliate_id', 'reseller_id');
        });

        Schema::table('affiliate_seo_settings', function (Blueprint $table) {
            $table->renameColumn('affiliate_id', 'reseller_id');
        });

        Schema::table('seo_scripts', function (Blueprint $table) {
            $table->renameColumn('affiliate_id', 'reseller_id');
        });

        Schema::table('redirects', function (Blueprint $table) {
            $table->renameColumn('affiliate_id', 'reseller_id');
        });

        Schema::table('affiliate_users', function (Blueprint $table) {
            $table->renameColumn('affiliate_id', 'reseller_id');
        });

        Schema::table('affiliate_subscriptions', function (Blueprint $table) {
            $table->renameColumn('affiliate_id', 'reseller_id');
            $table->renameColumn('affiliate_membership_tier_id', 'reseller_membership_tier_id');
        });

        Schema::table('affiliate_tier_changes', function (Blueprint $table) {
            $table->renameColumn('affiliate_id', 'reseller_id');
        });

        Schema::table('affiliate_impersonation_sessions', function (Blueprint $table) {
            $table->renameColumn('affiliate_id', 'reseller_id');
            $table->renameColumn('affiliate_user_id', 'reseller_user_id');
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->renameColumn('affiliate_id', 'reseller_id');
        });

        Schema::table('membership_otp_codes', function (Blueprint $table) {
            $table->renameColumn('affiliate_id', 'reseller_id');
        });

        Schema::table('membership_checkout_attempts', function (Blueprint $table) {
            $table->renameColumn('affiliate_id', 'reseller_id');
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->renameColumn('affiliate_user_id', 'reseller_user_id');
        });

        Schema::rename('affiliates', 'resellers');
        Schema::rename('affiliate_branding', 'reseller_branding');
        Schema::rename('affiliate_footer_settings', 'reseller_footer_settings');
        Schema::rename('affiliate_seo_settings', 'reseller_seo_settings');
        Schema::rename('affiliate_membership_tiers', 'reseller_membership_tiers');
        Schema::rename('affiliate_subscriptions', 'reseller_subscriptions');
        Schema::rename('affiliate_tier_changes', 'reseller_tier_changes');
        Schema::rename('affiliate_impersonation_sessions', 'reseller_impersonation_sessions');
        Schema::rename('affiliate_users', 'reseller_users');
        Schema::rename('affiliate_password_reset_tokens', 'reseller_password_reset_tokens');

        Schema::table('orders', function (Blueprint $table) use ($ordersAffiliateIdIndex) {
            if ($ordersAffiliateIdIndex !== null && $ordersAffiliateIdIndex['name'] !== 'orders_affiliate_id_foreign') {
                $table->index('reseller_id', 'orders_reseller_id_index');
            }

            $table->foreign('reseller_id')->references('id')->on('resellers')->restrictOnDelete();
        });

        Schema::table('reseller_branding', function (Blueprint $table) {
            $table->unique('reseller_id');
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::table('reseller_footer_settings', function (Blueprint $table) {
            $table->unique('reseller_id');
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::table('reseller_seo_settings', function (Blueprint $table) {
            $table->unique('reseller_id');
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::table('seo_scripts', function (Blueprint $table) {
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::table('redirects', function (Blueprint $table) {
            $table->unique(['reseller_id', 'from_path']);
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::table('reseller_users', function (Blueprint $table) {
            $table->index('reseller_id', 'reseller_users_reseller_id_index');
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::table('reseller_subscriptions', function (Blueprint $table) {
            $table->unique('reseller_id');
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
            $table->foreign('reseller_membership_tier_id')->references('id')->on('reseller_membership_tiers')->restrictOnDelete();
            $table->index('reseller_membership_tier_id', 'reseller_subs_tier_id_index');
        });

        Schema::table('reseller_tier_changes', function (Blueprint $table) {
            $table->index('reseller_id', 'reseller_tier_changes_reseller_id_index');
            $table->foreign('reseller_id')->references('id')->on('resellers')->nullOnDelete();
        });

        Schema::table('reseller_impersonation_sessions', function (Blueprint $table) {
            $table->index('reseller_id', 'reseller_impersonation_sessions_reseller_id_index');
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
            $table->foreign('reseller_user_id')->references('id')->on('reseller_users')->nullOnDelete();
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->unique(['reseller_id', 'email']);
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::table('membership_otp_codes', function (Blueprint $table) {
            $table->index(['reseller_id', 'email']);
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::table('membership_checkout_attempts', function (Blueprint $table) {
            $table->index(['reseller_id', 'email']);
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
        });

        Schema::table('withdrawals', function (Blueprint $table) {
            $table->foreign('reseller_user_id')->references('id')->on('reseller_users')->nullOnDelete();
        });
    }
};
