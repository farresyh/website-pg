<?php

namespace App\Services\Reseller\Bot;

use App\Models\Game;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\ResellerBotCommandLog;
use App\Services\Ledger\InsufficientBalanceException;
use App\Services\OpenWa\OpenWaClient;
use App\Services\Pricing\PricingService;
use App\Services\Reseller\NoResellerTierAssignedException;
use App\Services\Reseller\ResellerCatalogService;
use App\Services\Reseller\ResellerInactiveException;
use App\Services\Reseller\ResellerOrderPlacementRequest;
use App\Services\Reseller\ResellerOrderPlacementService;
use App\Services\Reseller\ResellerWalletService;
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
 */
final class ResellerBotService
{
    public function __construct(
        private readonly ResellerWhatsAppGroupService $groups,
        private readonly ResellerBotCommandParser $parser,
        private readonly ResellerCatalogService $catalog,
        private readonly PricingService $pricing,
        private readonly ResellerOrderPlacementService $placement,
        private readonly ResellerWalletService $wallet,
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

        $reply = match ($command->type) {
            ResellerBotCommandType::ListGames => $this->handleListGames(),
            ResellerBotCommandType::ListGamePackages => $this->handleListGamePackages($reseller, $command, $whatsappGroupId),
            ResellerBotCommandType::Order => $this->handleOrder($reseller, $command, $whatsappGroupId, $whatsappMessageId),
            ResellerBotCommandType::Balance => $this->handleBalance($reseller),
            ResellerBotCommandType::Unrecognized => $this->handleUnrecognized($reseller, $command, $whatsappGroupId),
        };

        $this->openWa->sendText($whatsappGroupId, $reply);
    }

    private function handleUnrecognized(Reseller $reseller, ResellerBotCommand $command, string $groupId): string
    {
        $this->logFailure($reseller, $groupId, $command->raw, 'unrecognized_command');

        return "Arahan tidak dikenali.\n\n"
            .".listharga - senarai semua game\n"
            .".list {kod} - senarai package & harga, contoh: .list MLMY\n"
            .".order {kod} {playerId} [{serverId}] - buat order\n"
            .'.baki - semak baki wallet';
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

        $lines = $games->map(fn (Game $game) => "{$game->reseller_code} - {$game->name}")->implode("\n");

        return "Senarai game:\n{$lines}\n\nGuna .list {kod} untuk harga, contoh: .list {$games->first()->reseller_code}";
    }

    private function handleListGamePackages(Reseller $reseller, ResellerBotCommand $command, string $groupId): string
    {
        $gameCode = (string) $command->gameCode;

        if (! Game::query()->where('reseller_code', $gameCode)->exists()) {
            $this->logFailure($reseller, $groupId, $command->raw, 'unknown_game_code');

            return "Kod game '{$gameCode}' tidak dijumpai. Guna .listharga untuk senarai kod yang sah.";
        }

        $prefix = $gameCode.'-';
        $items = $this->catalog->listAvailable()->filter(fn (array $row) => str_starts_with($row['code'], $prefix));

        if ($items->isEmpty()) {
            $this->logFailure($reseller, $groupId, $command->raw, 'no_packages_available');

            return "Tiada package tersedia untuk '{$gameCode}' buat masa ini.";
        }

        $tier = $reseller->tier;
        $lines = $items->map(function (array $row) use ($tier) {
            /** @var Package $package */
            $package = $row['package'];
            $pricing = $this->pricing->calculateForAffiliate(
                $package->cost_price,
                $package->standard_selling_price,
                (float) $tier->markup_percent,
                0.0,
            );

            return "{$row['code']} - {$package->name} - RM".self::formatSen($pricing->sellingPrice);
        })->implode("\n");

        return "Senarai package {$gameCode}:\n{$lines}\n\nGuna .order {kod} {playerId} [{serverId}] untuk order.";
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

        // ADR-075 decision 5: idempotency key derived from
        // (whatsapp_message_id, group_id) — a chat command carries no
        // client-generated key of its own, and OpenWA's webhook can
        // redeliver the same inbound message.
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
            ));
        } catch (ResellerInactiveException|NoResellerTierAssignedException $e) {
            $this->logFailure($reseller, $groupId, $command->raw, 'order_rejected: '.$e->getMessage());

            return 'Order tidak dapat diproses: '.$e->getMessage();
        } catch (InsufficientBalanceException) {
            $this->logFailure($reseller, $groupId, $command->raw, 'insufficient_balance');

            return 'Baki wallet tidak mencukupi.';
        }

        return "Order berjaya! No. Order: {$order->order_number}\nHarga: RM".self::formatSen($order->selling_price)."\nStatus akan dikemaskini sebentar lagi.";
    }

    private function handleBalance(Reseller $reseller): string
    {
        return 'Baki wallet anda: RM'.self::formatSen($this->wallet->balance($reseller));
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

    private static function formatSen(int $sen): string
    {
        return number_format($sen / 100, 2);
    }
}
