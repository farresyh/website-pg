<?php

namespace App\Services\Reseller\Bot;

use App\Http\Controllers\TrackOrderController;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Services\Order\DeliveryStatus;
use App\Services\PlayerValidation\PlayerValidationResult;
use Illuminate\Support\Collection;

/**
 * ADR-076 decision 2/9 — the one place every Bot reply string is built.
 * Extracted out of `ResellerBotService` so `.info` and the unrecognized-
 * command fallback share exactly one command-list copy (previously two
 * copies that had already drifted once, same session), and so message 2
 * (`SendResellerBotOrderNotification` listener) and message 1
 * (`ResellerBotService::handleOrder()`) share the same styling
 * primitives without the listener depending on the command orchestrator.
 *
 * Every structured, multi-line reply is wrapped in a WhatsApp monospace
 * code block (```) so key:value column alignment renders reliably
 * regardless of device font (decision 2) — a short one-line advisory
 * (rate limit, unlinked group) stays plain text, no box needed.
 */
final class ResellerBotReplyFormatter
{
    public static function listGames(Collection $games): string
    {
        $lines = $games->map(fn (Game $game) => "{$game->reseller_code} - {$game->name}")->implode("\n");

        return self::wrap(
            "🎮 MENU LIST HARGA\n\n"
            ."🛒 GAME TERSEDIA\n"
            ."{$lines}\n\n"
            .'Guna .list {kod} untuk lihat harga.'
            ."\nContoh: .list {$games->first()->reseller_code}"
        );
    }

    /**
     * @param  Collection<int, array{code: string, package: Package}>  $items  Already sorted (`Package::cheapestActivePerGame()`, ADR-076 decision 1).
     */
    public static function listPackages(Game $game, Collection $items, callable $sellingPriceSen): string
    {
        $lines = $items->map(function (array $row) use ($sellingPriceSen) {
            /** @var Package $package */
            $package = $row['package'];

            return "{$package->name}\n"
                .'Harga : RM'.self::formatSen($sellingPriceSen($package))."\n"
                ."Kod   : {$row['code']}";
        })->implode("\n━━━━━━━━━━━━━━━\n");

        $firstCode = $items->first()['code'];

        return self::wrap(
            "🛒 SENARAI PACKAGE — {$game->name}\n\n"
            ."{$lines}\n\n"
            .'Guna .order {kod} {playerId} [{serverId}] untuk order.'
            ."\nContoh: .order {$firstCode} 123456789"
        );
    }

    public static function orderPlaced(Order $order): string
    {
        return self::wrap(
            "「 PESANAN DITERIMA 」\n\n"
            ."No. Order : {$order->order_number}\n"
            .'Produk    : '.$order->package?->name."\n"
            .'Harga     : RM'.self::formatSen($order->selling_price)."\n\n"
            .'Sedang diproses, kami akan update sebentar lagi.'
        );
    }

    public static function orderUpdate(Order $order): string
    {
        $isDelivered = $order->delivery_status === DeliveryStatus::Delivered;

        $body = "「 UPDATE PESANAN 」\n\n"
            ."No. Order : {$order->order_number}\n"
            .'Status    : '.($isDelivered ? 'Berjaya ✅' : 'Gagal ❌')."\n"
            .'Produk    : '.$order->package?->name;

        if (! $isDelivered) {
            $body .= "\n\nSila hubungi admin jika perlu bantuan.";
        }

        return self::wrap($body);
    }

    public static function refundNotice(Order $order): string
    {
        return self::wrap(
            "「 REFUND WALLET 」\n\n"
            ."No. Order : {$order->order_number}\n"
            .'Jumlah    : RM'.self::formatSen($order->selling_price).' dikembalikan ke baki wallet anda.'
        );
    }

    public static function trackOrder(Order $order): string
    {
        // ADR-076 decision 7: the exact customer-safe shape/masking rule
        // `TrackOrderController` already enforces, reused verbatim —
        // this formatter only re-renders it as WhatsApp text, no new
        // money-safety logic of its own.
        $payload = TrackOrderController::customerSafePayload($order);

        return self::wrap(
            "「 STATUS PESANAN 」\n\n"
            ."No. Order    : {$payload['order_number']}\n"
            .'Produk       : '.($payload['package_name'] ?? '-')."\n"
            .'Player ID    : '.$payload['player_id'].($payload['server_id'] !== null ? " ({$payload['server_id']})" : '')."\n"
            .'Bayaran      : '.self::paymentStatusLabel($payload['payment_status'])."\n"
            .'Penghantaran : '.self::deliveryStatusLabel($payload['delivery_status'])
        );
    }

    public static function orderNotFound(): string
    {
        return 'Order tidak dijumpai. Sila semak semula no. order anda.';
    }

    public static function checkIdValid(PlayerValidationResult $result): string
    {
        return self::wrap(
            "✅ ID SAH\n\n"
            .'Nama   : '.($result->nickname ?? '-')
            .($result->countryCode !== null ? "\nNegara : {$result->countryCode}" : '')
        );
    }

    public static function checkIdInvalid(): string
    {
        return '❌ ID tidak sah atau tidak dijumpai.';
    }

    public static function checkIdUnsupported(): string
    {
        return 'Game ini tidak menyokong semakan ID.';
    }

    public static function checkIdUnavailable(): string
    {
        return 'Semakan ID tidak tersedia buat masa ini. Sila cuba sebentar lagi.';
    }

    public static function balance(int $sen): string
    {
        return 'Baki wallet anda: RM'.self::formatSen($sen);
    }

    public static function commandList(): string
    {
        return self::wrap(
            "🤖 SENARAI ARAHAN\n\n"
            .".listharga — senarai semua game\n"
            .".list {kod} — package & harga\n"
            .".order {kod} {playerId} [{serverId}] — buat order\n"
            .".trackorder {no_order} — semak status order\n"
            .".checkid {kod} {playerId} [{serverId}] — semak ID pemain\n"
            .'.baki — semak baki wallet'
        );
    }

    public static function unrecognized(): string
    {
        return "Arahan tidak dikenali.\n\n".self::commandList();
    }

    public static function formatSen(int $sen): string
    {
        return number_format($sen / 100, 2);
    }

    private static function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Berjaya',
            'pending' => 'Menunggu',
            'failed' => 'Gagal',
            default => ucfirst($status),
        };
    }

    private static function deliveryStatusLabel(string $status): string
    {
        return match ($status) {
            'not_started' => 'Belum Bermula',
            'processing' => 'Diproses',
            'pending' => 'Menunggu Pembekal',
            'delivered' => 'Berjaya',
            'failed' => 'Gagal',
            'needs_review' => 'Disemak Admin',
            default => ucfirst($status),
        };
    }

    private static function wrap(string $body): string
    {
        return "```\n{$body}\n```";
    }
}
