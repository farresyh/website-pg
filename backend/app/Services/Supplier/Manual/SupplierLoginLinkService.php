<?php

namespace App\Services\Supplier\Manual;

use App\Models\CredentialReveal;
use App\Models\Order;
use App\Models\OrderCredential;
use App\Models\PlatformSettings;
use App\Models\SupplierLoginLink;
use App\Services\Order\DeliveryStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-125 decisions 50-52 (ADR-124 2026-10-19 addendum): the supplier's
 * short login link, the one way a customer's secret login fields leave the
 * platform besides a super_admin reveal.
 *
 * Only the token's SHA-256 is stored. A link works only while its order is
 * still Pending on the same supplier with the same reference, so `.done`,
 * `.gagal` or a retry kills it with no revoke step; the login's wipe and the
 * D-ceiling expiry kill it too.
 */
final class SupplierLoginLinkService
{
    public const PLACEHOLDER = '{{login_link}}';

    private const TOKEN_LENGTH = 12;

    /** A fresh link for the order's current attempt, or null if it has no login to show. */
    public function mint(Order $order): ?string
    {
        $credential = OrderCredential::query()->where('order_id', $order->id)->first();

        if ($credential === null || $credential->purged_at !== null || ! $this->attemptIsOpen($order)) {
            return null;
        }

        // Str::random draws from random_bytes: base62, ~71 bits at 12 chars.
        $token = Str::random(self::TOKEN_LENGTH);

        SupplierLoginLink::query()->create([
            'token_hash' => self::hash($token),
            'order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'reference_number' => $order->reference_number,
            'expires_at' => now()->addDays(PlatformSettings::current()->credential_max_days),
        ]);

        return route('supplier-login.show', ['token' => $token]);
    }

    /** The link behind a token while it still opens, else null. */
    public function find(string $token): ?SupplierLoginLink
    {
        if (strlen($token) !== self::TOKEN_LENGTH) {
            return null;
        }

        $link = SupplierLoginLink::query()
            ->with(['order.game:id,name', 'order.package:id,name', 'supplier:id,name'])
            ->where('token_hash', self::hash($token))
            ->where('expires_at', '>', now())
            ->first();

        if ($link === null || $link->order === null
            || $link->order->supplier_id !== $link->supplier_id
            || $link->order->reference_number !== $link->reference_number
            || ! $this->attemptIsOpen($link->order)) {
            return null;
        }

        return $link;
    }

    /**
     * The non-secret login fields (username, login type), shown on the page
     * before and after the reveal; the group post carries them already.
     *
     * @return list<array{label: string, value: string}>
     */
    public function publicFields(SupplierLoginLink $link): array
    {
        return collect(OrderCredential::query()->where('order_id', $link->order_id)->first()?->fields ?? [])
            ->map(fn (array $field) => ['label' => $field['label'], 'value' => (string) $field['value']])
            ->all();
    }

    /**
     * The secret fields, each audited before it leaves (ADR-124 decision 6).
     * Null when the login was wiped in the meantime.
     *
     * @return list<array{label: string, value: string}>|null
     */
    public function reveal(SupplierLoginLink $link, ?string $ip): ?array
    {
        $credential = OrderCredential::query()->where('order_id', $link->order_id)->first();

        if ($credential === null || $credential->purged_at !== null) {
            return null;
        }

        $secrets = $credential->secrets ?? [];
        $fields = collect($credential->secret_fields)
            ->filter(fn (array $field) => isset($secrets[$field['key']]))
            ->values();

        DB::transaction(function () use ($fields, $link, $ip) {
            foreach ($fields as $field) {
                CredentialReveal::query()->create([
                    'order_id' => $link->order_id,
                    'actor_type' => CredentialReveal::ACTOR_SUPPLIER,
                    'actor_id' => $link->supplier_id,
                    'action' => CredentialReveal::ACTION_REVEAL,
                    'field_key' => $field['key'],
                    'ip' => $ip,
                    'created_at' => now(),
                ]);
            }
        });

        return $fields->map(fn (array $field) => ['label' => $field['label'], 'value' => (string) $secrets[$field['key']]])->all();
    }

    private function attemptIsOpen(Order $order): bool
    {
        return $order->delivery_status === DeliveryStatus::Pending && ! $order->is_test;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
