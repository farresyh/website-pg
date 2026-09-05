<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-076 PR-H decision 1: one row per Bot-channel `.topupbaki`
     * request, created by `ResellerBotService::handleTopupBaki()` right
     * after a successful `ResellerWalletTopupService::initiate()` call —
     * captures the exact `whatsapp_group_id` that started the top-up
     * (`reseller_whatsapp_groups.reseller_id` is not unique, so "the
     * reseller's group" is otherwise ambiguous) and tracks `notified_at`
     * so `ResellerBotWalletTopupNotifier` sends the "top-up berjaya"
     * message exactly once, even across a CHIP webhook redelivery.
     *
     * Structurally near-identical to `reseller_bot_order_notifications`
     * (ADR-076 decision 4) — deliberately a separate table, not new
     * columns on `wallet_topup_attempts`, keeping Bot-channel concerns
     * off the shared, money-adjacent model. The ADR's own consequence
     * note: if a third Bot-channel money action ever needs this shape,
     * that is the trigger to extract a shared base — not before.
     *
     * `wallet_topup_attempt_id` unique — one Bot notification row per
     * attempt, ever — and its uniqueness index doubles as the FK's
     * supporting index (`backend/AGENTS.md` standalone-index gotcha).
     * (ADR-076 PR-H decision 1 writes the column name as
     * `whatsapp_topup_attempt_id` — a wallet/whatsapp typo; the column
     * references `wallet_topup_attempts`, so it is named for that.)
     */
    public function up(): void
    {
        Schema::create('reseller_bot_wallet_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('resellers')->cascadeOnDelete();
            $table->foreignId('wallet_topup_attempt_id')->unique()->constrained('wallet_topup_attempts')->cascadeOnDelete();
            $table->string('whatsapp_group_id');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_bot_wallet_topups');
    }
};
