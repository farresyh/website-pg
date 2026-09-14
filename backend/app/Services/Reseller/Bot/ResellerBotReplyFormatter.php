<?php

namespace App\Services\Reseller\Bot;

use App\Http\Controllers\TrackOrderController;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\WalletTopupAttempt;
use App\Services\Order\DeliveryStatus;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\Reseller\ResellerWalletTopupService;
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
     * OpenWA's own `send-text` endpoint hard-rejects any message body over
     * this length with a `400` (`MESSAGE_TEXT_MAX_LENGTH`, OpenWA's
     * `src/modules/message/dto/send-message.dto.ts`) — before the text
     * ever reaches WhatsApp, and with no response body our client logs.
     * Found 2026-09-13: `.list MLID` (79 active denominations, the
     * largest single-game catalogue) built a 7,605-char reply and every
     * send silently failed after exhausting `SendResellerBotReplyJob`'s
     * 3 retries — the reseller saw nothing at all. Re-running the same
     * length check across every reseller-coded game found two more over
     * the cap: `MLMY` (5,707 chars, 59 packages) and `MLGB` (4,164 chars,
     * 43 packages) — not a one-game edge case, a property of package
     * count that any game (including a new one) can grow into.
     */
    public const MAX_MESSAGE_LENGTH = 4096;

    /**
     * Splits into multiple WhatsApp messages once the full listing would
     * cross `MAX_MESSAGE_LENGTH` — the cap is per-message, so each chunk
     * gets its own ``` fence. Always breaks between packages, never
     * inside one. Below the cap this still returns a single-element
     * array with byte-for-byte the same text as before this method
     * chunked (no "(1/1)" clutter) — every game short enough to fit in
     * one message keeps rendering exactly as it did before this method
     * existed.
     *
     * @param  Collection<int, array{code: string, package: Package}>  $items  Already sorted (`Package::cheapestActivePerGame()`, ADR-076 decision 1).
     * @return list<string>
     */
    public static function listPackages(Game $game, Collection $items, callable $sellingPriceSen): array
    {
        $blocks = $items->map(function (array $row) use ($sellingPriceSen) {
            /** @var Package $package */
            $package = $row['package'];

            return "{$package->name}\n"
                .'Harga : RM'.self::formatSen($sellingPriceSen($package))."\n"
                ."Kod   : {$row['code']}";
        })->all();

        $separator = "\n━━━━━━━━━━━━━━━\n";
        $firstCode = $items->first()['code'];
        $footer = "\n\n".'Guna .order {kod} {playerId} [{serverId}] untuk order.'
            ."\nContoh: .order {$firstCode} 123456789";

        // The header carries a "(part/total)" suffix once split into more
        // than one message. Reserve room for the widest plausible count
        // (two digits either side) up front, before the real — always
        // shorter or equal — suffix is substituted in below, so the
        // reserved budget below is never an overestimate that lets a
        // finished chunk slip back over the cap.
        $header = fn (string $suffix) => "🛒 SENARAI PACKAGE — {$game->name}{$suffix}\n\n";
        $wrapOverhead = strlen("```\n") + strlen("\n```");
        $budget = self::MAX_MESSAGE_LENGTH - $wrapOverhead - strlen($header(' (99/99)')) - strlen($footer);

        $chunks = [];
        $current = '';
        foreach ($blocks as $block) {
            $addition = $current === '' ? $block : $separator.$block;

            if ($current !== '' && strlen($current.$addition) > $budget) {
                $chunks[] = $current;
                $current = $block;

                continue;
            }

            $current .= $addition;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        $total = count($chunks);

        return array_values(array_map(function (string $body, int $index) use ($header, $total, $footer) {
            $suffix = $total > 1 ? ' ('.($index + 1)."/{$total})" : '';
            $isLast = $index === $total - 1;

            return self::wrap($header($suffix).$body.($isLast ? $footer : ''));
        }, $chunks, array_keys($chunks)));
    }

    /**
     * ADR-093 decision 4 — echoes the player ID (and server ID, when
     * present) back for every order, not just validator-covered games,
     * so the reseller can catch a fat-fingered ID by re-reading their
     * own just-typed value a few seconds later.
     */
    public static function orderPlaced(Order $order): string
    {
        $playerLine = 'Player ID : '.$order->player_id
            .($order->server_id !== null ? " ({$order->server_id})" : '')."\n";

        return self::wrap(
            "「 PESANAN DITERIMA 」\n\n"
            ."No. Order : {$order->order_number}\n"
            .$playerLine
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

    public static function checkIdWrongRegion(PlayerValidationResult $result, Game $correctGame): string
    {
        return self::wrap(
            "⚠️ ID SAH — REGION LAIN\n\n"
            .'Nama   : '.($result->nickname ?? '-')."\n"
            .'Negara : '.$result->countryCode."\n\n"
            ."Player ini untuk {$correctGame->name}.\n"
            ."Guna kod {$correctGame->reseller_code} untuk .list / .order."
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

    /**
     * ADR-076 PR-H decision 5 — reply on a successful `.topupbaki`.
     * `checkout_url` is non-null by the time this is called (the handler
     * routes a null-link attempt to `topupCheckoutFailed()` instead).
     */
    public static function topupInitiated(WalletTopupAttempt $attempt): string
    {
        return self::wrap(
            "「 TOP-UP WALLET 」\n\n"
            .'Jumlah    : RM'.self::formatSen($attempt->amount_sen)."\n"
            .'Caj       : RM'.self::formatSen($attempt->total_charged_sen)." (termasuk fi bank)\n"
            .'Ref       : '.$attempt->reference."\n\n"
            .$attempt->checkout_url."\n\n"
            .'Klik untuk bayar, link sah selama 30 minit.'
        );
    }

    /**
     * ADR-076 PR-H decision 3 — a reseller who already has a pending
     * attempt gets that attempt's own link back, not a plain rejection,
     * so a lost first message is always self-recoverable.
     */
    public static function topupAlreadyPending(WalletTopupAttempt $attempt): string
    {
        return self::wrap(
            "「 TOP-UP WALLET 」\n\n"
            ."Anda sudah ada satu top-up yang belum dibayar.\n"
            .'Jumlah    : RM'.self::formatSen($attempt->amount_sen)."\n"
            .'Ref       : '.$attempt->reference."\n\n"
            .$attempt->checkout_url."\n\n"
            .'Klik untuk bayar, atau tunggu ia tamat tempoh sebelum buat yang baharu.'
        );
    }

    /** ADR-076 PR-H decision 2 — message 2, sent once the CHIP webhook confirms payment. */
    public static function topupPaid(int $balanceSen): string
    {
        return self::wrap(
            "「 TOP-UP BERJAYA 」\n\n"
            .'Baki wallet anda sekarang: RM'.self::formatSen($balanceSen)
        );
    }

    /** ADR-076 PR-H decision 5 — below-minimum amount, replied without a wasted CHIP call. */
    public static function topupBelowMinimum(): string
    {
        return 'Jumlah minimum top-up ialah RM'.self::formatSen(ResellerWalletTopupService::MIN_AMOUNT_SEN)
            .'. Contoh: .topupbaki 50';
    }

    public static function topupInvalidAmount(): string
    {
        return 'Jumlah tidak sah. Masukkan jumlah dalam RM, contoh: .topupbaki 50';
    }

    public static function topupUnavailable(): string
    {
        return 'Top-up wallet tidak tersedia buat masa ini. Sila hubungi admin.';
    }

    public static function topupCheckoutFailed(): string
    {
        return 'Gagal memulakan top-up. Sila cuba sebentar lagi.';
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
            .".baki — semak baki wallet\n"
            .'.topupbaki {jumlah} — top-up baki wallet (RM)'
        );
    }

    public static function unrecognized(): string
    {
        return "Arahan tidak dikenali.\n\n".self::commandList();
    }

    /**
     * E10 hardening (2026-09-10 reseller-family audit, `docs/build-log.md`):
     * shared copy for the read-only commands (`.baki`/`.trackorder`/
     * `.listharga`) that now guard on `Reseller.is_active`, matching
     * `.order`'s own `ResellerInactiveException` rejection in spirit —
     * a clean reseller-facing message rather than leaking an internal
     * exception string.
     */
    public static function resellerInactive(): string
    {
        return 'Akaun reseller ini telah dinyahaktifkan. Sila hubungi admin.';
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
