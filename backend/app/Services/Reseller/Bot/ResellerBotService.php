<?php

namespace App\Services\Reseller\Bot;

use App\Models\Game;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PlayerRegionMapping;
use App\Models\PlayerValidation;
use App\Models\Reseller;
use App\Models\ResellerBotCommandLog;
use App\Models\ResellerBotOrderNotification;
use App\Models\ResellerBotWalletTopup;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\InsufficientBalanceException;
use App\Services\OpenWa\OpenWaClient;
use App\Services\PlayerValidation\PlayerValidatorRegistry;
use App\Services\PlayerValidation\ProviderUnavailableException;
use App\Services\PlayerValidation\UnsupportedPlayerValidatorException;
use App\Services\Pricing\OrderPricingResolver;
use App\Services\Reseller\InvalidWalletTopupAmountException;
use App\Services\Reseller\NoResellerTierAssignedException;
use App\Services\Reseller\PendingWalletTopupAlreadyExistsException;
use App\Services\Reseller\ResellerCatalogService;
use App\Services\Reseller\ResellerInactiveException;
use App\Services\Reseller\ResellerOrderPlacementRequest;
use App\Services\Reseller\ResellerOrderPlacementService;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\ResellerWalletTopupService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use App\Services\Reseller\WalletTopupCheckoutFailedException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * ADR-075 / PR-F build addendum — the Reseller Bot channel's command
 * orchestrator. `OpenWaWebhookController` calls `handle()` for every
 * inbound group message; this is the one place that resolves the
 * reseller (via group mapping), enforces the per-reseller rate limit
 * (ADR-073 decision 9, restated by ADR-075 decision 5), and dispatches
 * to the same `ResellerCatalogService`/`ResellerOrderPlacementService`/
 * `ResellerWalletService` seams the Reseller API (PR-E) already calls —
 * this channel adds no new money/catalog logic of its own.
 *
 * ADR-076 adds `.trackorder`/`.checkid`/`.info` and the two-stage order
 * lifecycle (message 1 here at placement; message 2 is
 * `SendResellerBotOrderNotification`, a separate `OrderStatusUpdated`
 * listener — this class only creates the `ResellerBotOrderNotification`
 * row that listener needs). All reply copy lives in
 * `ResellerBotReplyFormatter`, not inline here.
 */
final class ResellerBotService
{
    public function __construct(
        private readonly ResellerWhatsAppGroupService $groups,
        private readonly ResellerBotCommandParser $parser,
        private readonly ResellerCatalogService $catalog,
        private readonly OrderPricingResolver $pricingResolver,
        private readonly ResellerOrderPlacementService $placement,
        private readonly ResellerWalletService $wallet,
        private readonly ResellerWalletTopupService $topups,
        private readonly PlayerValidatorRegistry $validators,
        private readonly OpenWaClient $openWa,
    ) {}

    public function handle(string $whatsappGroupId, string $rawText, string $whatsappMessageId): void
    {
        $reseller = $this->groups->resolveReseller($whatsappGroupId);

        if ($reseller === null) {
            // PR-F build addendum decision 3 — any group message captures/
            // bumps the pending-link row (this IS the "send a test message"
            // onboarding flow), but a reply is only worth sending back for
            // something that looks like an attempted command — replying to
            // every ordinary chat message in a not-yet-linked group would
            // be spam, not a courtesy.
            $this->groups->capturePending($whatsappGroupId, $rawText);

            if (str_starts_with(trim($rawText), '.')) {
                $this->openWa->sendText($whatsappGroupId, 'Group ini belum dikaitkan dengan mana-mana akaun reseller. Sila hubungi admin untuk aktifkan.');
            }

            return;
        }

        if (! RateLimiter::attempt("reseller-bot:{$reseller->id}", 60, fn () => true, 60)) {
            $this->openWa->sendText($whatsappGroupId, 'Terlalu banyak permintaan. Sila cuba sebentar lagi.');

            return;
        }

        $command = $this->parser->parse($rawText);

        // E10 hardening (2026-09-10 reseller-family audit, `docs/build-log.md`):
        // `.order`/`.topupbaki` already reject a deactivated Reseller via
        // their own services (`ResellerInactiveException`) — these three
        // read-only commands didn't, so a deactivated reseller could still
        // read their own balance/order-history/catalog through the bot.
        if (! $reseller->is_active && in_array($command->type, [
            ResellerBotCommandType::Balance,
            ResellerBotCommandType::TrackOrder,
            ResellerBotCommandType::ListGames,
        ], true)) {
            $this->logFailure($reseller, $whatsappGroupId, $command->raw, 'reseller_inactive');
            $this->openWa->sendText($whatsappGroupId, ResellerBotReplyFormatter::resellerInactive());

            return;
        }

        // ADR-076 decision 8 — a separate, stricter limit for `.checkid`
        // specifically: every other command here is a plain DB read,
        // this one can call a paid external provider (Moogold/
        // AcidGameShop/Nexone) per invocation.
        if ($command->type === ResellerBotCommandType::CheckId
            && ! RateLimiter::attempt("reseller-bot-checkid:{$reseller->id}", 10, fn () => true, 60)) {
            $this->openWa->sendText($whatsappGroupId, 'Terlalu banyak permintaan semakan ID. Sila cuba sebentar lagi.');

            return;
        }

        // ADR-076 PR-H decision 4 — a stricter limit still for
        // `.topupbaki` (5/min): every successful call mints a real CHIP
        // payment reference, the most financially-sensitive command in
        // this set. A genuinely independent counter from the 60/min and
        // checkid 10/min keys (distinct namespace).
        if ($command->type === ResellerBotCommandType::TopupBaki
            && ! RateLimiter::attempt("reseller-bot-topupbaki:{$reseller->id}", 5, fn () => true, 60)) {
            $this->openWa->sendText($whatsappGroupId, 'Terlalu banyak permintaan top-up. Sila cuba sebentar lagi.');

            return;
        }

        $reply = match ($command->type) {
            ResellerBotCommandType::ListGames => $this->handleListGames(),
            ResellerBotCommandType::ListGamePackages => $this->handleListGamePackages($reseller, $command, $whatsappGroupId),
            ResellerBotCommandType::Order => $this->handleOrder($reseller, $command, $whatsappGroupId, $whatsappMessageId),
            ResellerBotCommandType::Balance => $this->handleBalance($reseller),
            ResellerBotCommandType::TrackOrder => $this->handleTrackOrder($reseller, $command, $whatsappGroupId),
            ResellerBotCommandType::CheckId => $this->handleCheckId($reseller, $command, $whatsappGroupId),
            ResellerBotCommandType::Info => ResellerBotReplyFormatter::commandList(),
            ResellerBotCommandType::TopupBaki => $this->handleTopupBaki($reseller, $command, $whatsappGroupId),
            ResellerBotCommandType::Unrecognized => $this->handleUnrecognized($reseller, $command, $whatsappGroupId),
        };

        $this->openWa->sendText($whatsappGroupId, $reply);
    }

    private function handleUnrecognized(Reseller $reseller, ResellerBotCommand $command, string $groupId): string
    {
        $this->logFailure($reseller, $groupId, $command->raw, 'unrecognized_command');

        return ResellerBotReplyFormatter::unrecognized();
    }

    private function handleListGames(): string
    {
        $games = Game::query()
            ->whereNotNull('reseller_code')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['name', 'reseller_code']);

        if ($games->isEmpty()) {
            return 'Tiada game tersedia buat masa ini.';
        }

        return ResellerBotReplyFormatter::listGames($games);
    }

    private function handleListGamePackages(Reseller $reseller, ResellerBotCommand $command, string $groupId): string
    {
        $gameCode = (string) $command->gameCode;
        $game = Game::query()->where('reseller_code', $gameCode)->first();

        if ($game === null) {
            $this->logFailure($reseller, $groupId, $command->raw, 'unknown_game_code');

            return "Kod game '{$gameCode}' tidak dijumpai. Guna .listharga untuk senarai kod yang sah.";
        }

        $prefix = $gameCode.'-';
        // Already sorted ascending by denomination (ADR-076 decision 1,
        // `Package::cheapestActivePerGame()`) — no re-sort needed here.
        $items = $this->catalog->listAvailable()->filter(fn (array $row) => str_starts_with($row['code'], $prefix));

        if ($items->isEmpty()) {
            $this->logFailure($reseller, $groupId, $command->raw, 'no_packages_available');

            return "Tiada package tersedia untuk '{$gameCode}' buat masa ini.";
        }

        $tier = $reseller->tier;
        // ADR-060 PR-4b: the same seam ResellerOrderPlacementService
        // charges through, so the listed price can never drift from the
        // debited price.
        $sellingPriceSen = fn ($package) => $this->pricingResolver->resolveResellerWallet(
            $package->cost_price,
            $package->standard_selling_price,
            (float) $tier->markup_percent,
        )->sellingPriceSen;

        return ResellerBotReplyFormatter::listPackages($game, $items, $sellingPriceSen);
    }

    private function handleOrder(Reseller $reseller, ResellerBotCommand $command, string $groupId, string $whatsappMessageId): string
    {
        $package = $this->catalog->resolveByCode((string) $command->productCode);

        if ($package === null) {
            $this->logFailure($reseller, $groupId, $command->raw, 'unknown_product_code');

            return "Kod produk '{$command->productCode}' tidak dijumpai atau tidak tersedia.";
        }

        $game = Game::query()->find($package->game_id);
        $extraField = $game?->validation_rules['extra_field'] ?? null;

        if ($extraField !== null && $command->serverId === null) {
            $this->logFailure($reseller, $groupId, $command->raw, 'missing_server_id');

            return "Game ini memerlukan {$extraField}. Format: .order {$command->productCode} {playerId} {serverId}";
        }

        if ($game !== null && $game->player_validator_enabled && $game->player_validator_profile_id !== null) {
            $rejection = $this->validatePlayerForOrder($reseller, $command, $groupId, $game);
            if ($rejection !== null) {
                return $rejection;
            }
        }

        // ADR-075 decision 5: idempotency key derived from
        // (whatsapp_message_id, group_id) — a chat command carries no
        // client-generated key of its own, and OpenWA's webhook can
        // redeliver the same inbound message.
        //
        // E7 hardening (2026-09-10 reseller-family audit, `docs/build-log.md`):
        // this formula's safety depends on `$whatsappMessageId` staying
        // identical across a redelivery of the *same* event — verified
        // against OpenWA's own published changelog (docs.open-wa.org,
        // checked 2026-09-12): webhook delivery is a documented
        // at-least-once contract, and "deliveries are now recorded
        // before they are attempted, and a bounded sweep replays
        // whatever is stranded under its stored idempotency key" — i.e.
        // a redelivery replays the same persisted payload, not a
        // regenerated one, so `data.id` is stable across it. No code
        // change follows from this — it confirms the existing formula
        // was already correct.
        $idempotencyKey = "wa:{$groupId}:{$whatsappMessageId}";

        try {
            $order = $this->placement->placeOrder($reseller, new ResellerOrderPlacementRequest(
                playerId: (string) $command->playerId,
                serverId: $command->serverId,
                costPriceSen: $package->cost_price,
                standardSellingPriceSen: $package->standard_selling_price,
                idempotencyKey: $idempotencyKey,
                supplierProductRef: $package->supplier_package_ref,
                gameId: $package->game_id,
                packageId: $package->id,
                supplierId: $package->supplier_id,
            ))->order;
        } catch (ResellerInactiveException|NoResellerTierAssignedException $e) {
            $this->logFailure($reseller, $groupId, $command->raw, 'order_rejected: '.$e->getMessage());

            return 'Order tidak dapat diproses: '.$e->getMessage();
        } catch (InsufficientBalanceException) {
            $this->logFailure($reseller, $groupId, $command->raw, 'insufficient_balance');

            return 'Baki wallet tidak mencukupi.';
        }

        // ADR-076 decision 4 — capture the exact originating group now,
        // at placement time, so message 2 (SendResellerBotOrderNotification)
        // knows where to reply once delivery resolves. `updateOrCreate`
        // rather than `create`: `placeOrder()`'s own idempotency check
        // above means this is normally a fresh order, but a redelivered
        // webhook resolving to the same existing order must not throw on
        // the table's `order_id` uniqueness.
        ResellerBotOrderNotification::query()->updateOrCreate(
            ['order_id' => $order->id],
            ['whatsapp_group_id' => $groupId],
        );

        return ResellerBotReplyFormatter::orderPlaced($order);
    }

    /**
     * ADR-093 decision 2 — auto-runs the same PlayerValidatorRegistry
     * check `.checkid` already uses, before placeOrder() is called, so
     * a caught problem never reaches the debit. Mirrors handleCheckId()'s
     * own validate-then-resolve-region logic (reuse, not a parallel
     * implementation) — the one deliberate difference is that both an
     * invalid ID *and* a wrong-region match are logged as `.order`
     * rejection reasons here (handleCheckId() only logs the invalid
     * case), since this is blocking a real order attempt, not just
     * answering an informational query. Returns null to let the order
     * proceed, or a reply string to reject it.
     */
    private function validatePlayerForOrder(Reseller $reseller, ResellerBotCommand $command, string $groupId, Game $game): ?string
    {
        // Decision 3 — the exact same bucket/limit ADR-076 decision 8
        // already gives `.checkid` (10/min per reseller); exhausting it
        // here behaves identically to exhausting it via `.checkid`
        // directly, not a silent skip of this safety check.
        if (! RateLimiter::attempt("reseller-bot-checkid:{$reseller->id}", 10, fn () => true, 60)) {
            return 'Terlalu banyak permintaan semakan ID. Sila cuba sebentar lagi.';
        }

        $profile = $game->playerValidatorProfile()->firstOrFail();

        try {
            $result = $this->validators->resolve($profile->key)->validate((string) $command->playerId, $command->serverId);
        } catch (UnsupportedPlayerValidatorException|ProviderUnavailableException) {
            // Decision 2 — fail-open: an outage or misconfiguration in an
            // unofficial third-party integration must never block a
            // legitimate paid order. Degrades to exactly today's
            // no-validator behaviour, not worse.
            return null;
        }

        $wrongRegionGame = null;
        if ($result->valid && $result->countryCode !== null) {
            $mapping = PlayerRegionMapping::query()
                ->where('player_validator_profile_id', $profile->id)
                ->where('country_code', $result->countryCode)
                ->with('game:id,name,reseller_code')
                ->first();

            if ($mapping !== null && $mapping->game_id !== $game->id) {
                $wrongRegionGame = $mapping->game;
            }
        }

        // Same audit trail handleCheckId() already writes for every
        // attempt — this is the identical underlying check, just
        // triggered from .order instead of .checkid.
        PlayerValidation::query()->create([
            'game_id' => $game->id,
            'player_id' => (string) $command->playerId,
            'server_id' => $command->serverId,
            'status' => $this->checkIdStatus($result->valid, $wrongRegionGame),
            'country_code' => $result->countryCode,
            'nickname' => $result->nickname,
            'provider' => $result->provider,
            'validated_at' => now(),
        ]);

        if (! $result->valid) {
            $this->logFailure($reseller, $groupId, $command->raw, 'invalid_player_id');

            return ResellerBotReplyFormatter::checkIdInvalid();
        }

        if ($wrongRegionGame !== null) {
            $this->logFailure($reseller, $groupId, $command->raw, 'wrong_region_player_id');

            return ResellerBotReplyFormatter::checkIdWrongRegion($result, $wrongRegionGame);
        }

        return null;
    }

    private function handleTrackOrder(Reseller $reseller, ResellerBotCommand $command, string $groupId): string
    {
        $order = Order::query()->where('order_number', (string) $command->orderNumber)->first();

        // ADR-076 decision 7 — a wrong order number and a real order
        // belonging to a different Reseller return the identical reply,
        // deliberately: this command must never confirm another
        // reseller's order number exists.
        if ($order === null || $order->wallet_reseller_id !== $reseller->id) {
            $this->logFailure($reseller, $groupId, $command->raw, 'order_not_found_or_not_owned');

            return ResellerBotReplyFormatter::orderNotFound();
        }

        return ResellerBotReplyFormatter::trackOrder($order);
    }

    private function handleCheckId(Reseller $reseller, ResellerBotCommand $command, string $groupId): string
    {
        $gameCode = (string) $command->gameCode;
        $game = Game::query()->where('reseller_code', $gameCode)->first();

        if ($game === null) {
            $this->logFailure($reseller, $groupId, $command->raw, 'unknown_game_code');

            return "Kod game '{$gameCode}' tidak dijumpai. Guna .listharga untuk senarai kod yang sah.";
        }

        if (! $game->player_validator_enabled || $game->player_validator_profile_id === null) {
            return ResellerBotReplyFormatter::checkIdUnsupported();
        }

        $profile = $game->playerValidatorProfile()->firstOrFail();

        try {
            $result = $this->validators->resolve($profile->key)->validate((string) $command->playerId, $command->serverId);
        } catch (UnsupportedPlayerValidatorException|ProviderUnavailableException) {
            $this->logFailure($reseller, $groupId, $command->raw, 'player_validator_unavailable');

            return ResellerBotReplyFormatter::checkIdUnavailable();
        }

        // ADR-076 decision 8's own "skip region resolution" was revised
        // after live-testing: `.checkid mlid <a Malaysian player's id>`
        // replied "valid, MY" — technically true (the id resolves) but
        // misleading, since that player can't be topped up through the
        // Indonesia game code. The region check mirrors
        // `PlayerValidationController::resolveState()`: if the player's
        // country maps (in `player_region_mappings`, admin-curated per
        // profile) to a *different* game than the one whose `reseller_code`
        // was used, tell the reseller which code to use instead. Degrades
        // gracefully to the plain "valid + country" reply when no mapping
        // row exists (data not set up) — no worse than before.
        $wrongRegionGame = null;
        if ($result->valid && $result->countryCode !== null) {
            $mapping = PlayerRegionMapping::query()
                ->where('player_validator_profile_id', $profile->id)
                ->where('country_code', $result->countryCode)
                ->with('game:id,name,reseller_code')
                ->first();

            if ($mapping !== null && $mapping->game_id !== $game->id) {
                $wrongRegionGame = $mapping->game;
            }
        }

        // Same audit trail every other validation attempt writes to
        // (`PlayerValidationController::record()`).
        PlayerValidation::query()->create([
            'game_id' => $game->id,
            'player_id' => (string) $command->playerId,
            'server_id' => $command->serverId,
            'status' => $this->checkIdStatus($result->valid, $wrongRegionGame),
            'country_code' => $result->countryCode,
            'nickname' => $result->nickname,
            'provider' => $result->provider,
            'validated_at' => now(),
        ]);

        if (! $result->valid) {
            $this->logFailure($reseller, $groupId, $command->raw, 'invalid_player_id');

            return ResellerBotReplyFormatter::checkIdInvalid();
        }

        if ($wrongRegionGame !== null) {
            return ResellerBotReplyFormatter::checkIdWrongRegion($result, $wrongRegionGame);
        }

        return ResellerBotReplyFormatter::checkIdValid($result);
    }

    private function checkIdStatus(bool $valid, ?Game $wrongRegionGame): string
    {
        if (! $valid) {
            return 'invalid';
        }

        return $wrongRegionGame !== null ? 'wrong_region' : 'valid';
    }

    private function handleBalance(Reseller $reseller): string
    {
        return ResellerBotReplyFormatter::balance($this->wallet->balance($reseller));
    }

    /**
     * ADR-076 PR-H — self-serve wallet top-up over WhatsApp. Reuses
     * `ResellerWalletTopupService::initiate()` (PR-G's portal flow) with
     * zero changes: parse the RM amount, pre-check it against the
     * service's own minimum (no wasted CHIP call below it), pick the
     * same default payment channel the portal Wallet screen defaults to
     * (first active method, `PaymentMethodCatalogController`'s own
     * `category`/`label` ordering), then record a
     * `ResellerBotWalletTopup` row so message 2 (the webhook-confirmed
     * "berjaya") knows which group to reply into.
     */
    private function handleTopupBaki(Reseller $reseller, ResellerBotCommand $command, string $groupId): string
    {
        $raw = (string) $command->amount;

        // RM, up to 2 decimals. A bare integer ("50") or "50.50" — never
        // a currency symbol, thousands separator, or negative.
        if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $raw) !== 1) {
            $this->logFailure($reseller, $groupId, $command->raw, 'topup_invalid_amount');

            return ResellerBotReplyFormatter::topupInvalidAmount();
        }

        $amountSen = (int) round(((float) $raw) * 100);

        if ($amountSen < ResellerWalletTopupService::MIN_AMOUNT_SEN) {
            $this->logFailure($reseller, $groupId, $command->raw, 'topup_below_minimum');

            return ResellerBotReplyFormatter::topupBelowMinimum();
        }

        // Same default the portal Wallet screen uses — the first active
        // payment method in `PaymentMethodCatalogController`'s ordering.
        // The Bot channel has no in-chat channel picker; a reseller who
        // needs a specific method uses the portal.
        $method = PaymentMethod::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('label')
            ->first();

        if ($method === null) {
            $this->logFailure($reseller, $groupId, $command->raw, 'topup_no_active_payment_method');

            return ResellerBotReplyFormatter::topupUnavailable();
        }

        try {
            $attempt = $this->topups->initiate($reseller, $amountSen, $method->channel_code, []);
        } catch (InvalidWalletTopupAmountException) {
            // Defensive — the pre-check above already covers this.
            return ResellerBotReplyFormatter::topupBelowMinimum();
        } catch (PendingWalletTopupAlreadyExistsException) {
            // ADR-076 PR-H decision 3 — hand back the existing attempt's
            // own link rather than a plain rejection.
            $pending = WalletTopupAttempt::query()
                ->where('reseller_id', $reseller->id)
                ->where('status', WalletTopupAttemptStatus::Pending->value)
                ->where('expires_at', '>', now())
                ->latest('id')
                ->first();

            return $pending?->checkout_url !== null
                ? ResellerBotReplyFormatter::topupAlreadyPending($pending)
                : ResellerBotReplyFormatter::topupCheckoutFailed();
        } catch (WalletTopupCheckoutFailedException) {
            $this->logFailure($reseller, $groupId, $command->raw, 'topup_checkout_failed');

            return ResellerBotReplyFormatter::topupCheckoutFailed();
        }

        if ($attempt->checkout_url === null) {
            $this->logFailure($reseller, $groupId, $command->raw, 'topup_checkout_url_missing');

            return ResellerBotReplyFormatter::topupCheckoutFailed();
        }

        ResellerBotWalletTopup::query()->updateOrCreate(
            ['wallet_topup_attempt_id' => $attempt->id],
            ['reseller_id' => $reseller->id, 'whatsapp_group_id' => $groupId],
        );

        return ResellerBotReplyFormatter::topupInitiated($attempt);
    }

    private function logFailure(Reseller $reseller, string $groupId, string $raw, string $reason): void
    {
        ResellerBotCommandLog::query()->create([
            'reseller_id' => $reseller->id,
            'whatsapp_group_id' => $groupId,
            'raw_command' => Str::limit($raw, 250, ''),
            'failure_reason' => $reason,
        ]);

        Log::info('Reseller Bot command failed', ['reseller_id' => $reseller->id, 'reason' => $reason]);
    }
}
