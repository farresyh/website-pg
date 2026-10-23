<?php

namespace App\Console\Commands\Credentials;

use App\Models\OrderCredential;
use App\Models\PlatformSettings;
use App\Services\Order\PaymentStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * ADR-124 decision 7, as revised by its 2026-10-18 addendum: the one place
 * a customer's login is wiped, by rule, not by a hook in each of the ~8
 * delivery-status writers. Every 15 minutes (TaskSchedule, critical):
 *
 *  1. stamp `closed_at` on a login whose order is now closed
 *     (`Order::scopeClosed()`: Delivered or compensated — both final);
 *  2. wipe a login that is still held when any of these is true:
 *     - closed at least G hours ago;
 *     - its order is not paid (pending or failed) H hours after creation
 *       (a Paid can land after a Failed, so a failed payment waits too);
 *     - its ceiling `secrets_expire_at` has passed (D days, or the game's
 *       own days, from when the secrets were stored or last replaced;
 *       ADR-126 2026-10-23 addendum, decision 22).
 *
 * A wipe nulls `secrets` and sets `purged_at` with a plain UPDATE: nothing
 * is decrypted, so a rotated APP_KEY can't stop it. Consent and the
 * non-secret fields are kept. Only unwiped rows are read (indexed
 * `purged_at`), i.e. in-flight orders.
 */
#[Signature('app:wipe-order-credentials')]
#[Description('Wipe customer game logins once their order is closed, unpaid, or past the ceiling (ADR-124).')]
class WipeOrderCredentialsCommand extends Command
{
    public function handle(): int
    {
        $settings = PlatformSettings::current();
        $now = now();

        $closed = OrderCredential::query()
            ->whereNull('purged_at')
            ->whereNull('closed_at')
            ->whereHas('order', fn (Builder $q) => $q->closed())
            ->update(['closed_at' => $now]);

        $wiped = OrderCredential::query()
            ->whereNull('purged_at')
            ->where(fn (Builder $q) => $q
                ->where('closed_at', '<=', $now->copy()->subHours($settings->credential_close_grace_hours))
                ->orWhereHas('order', fn (Builder $o) => $o
                    ->where('payment_status', '!=', PaymentStatus::Paid->value)
                    ->where('created_at', '<=', $now->copy()->subHours($settings->credential_unpaid_hours)))
                ->orWhere('secrets_expire_at', '<=', $now))
            ->update(['secrets' => null, 'purged_at' => $now]);

        $this->info("Stamped {$closed} closed, wiped {$wiped} order credential(s).");
        if ($closed > 0 || $wiped > 0) {
            Log::info('Order credentials swept', ['closed' => $closed, 'wiped' => $wiped]);
        }

        return self::SUCCESS;
    }
}
