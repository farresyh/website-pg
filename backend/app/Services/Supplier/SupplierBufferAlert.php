<?php

namespace App\Services\Supplier;

use App\Models\Supplier;
use App\Services\Backup\BackupFailureAlerter;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * ADR-115 2026-11-05 addendum, decision 7: one email to every admin per
 * supplier per Malaysia day while it is short; none on recovery.
 * `low_balance_alerted_at` is claimed with a conditional update (the
 * CheckPriceSyncStalenessCommand pattern), so two checks racing on the same
 * drop send one email.
 */
final class SupplierBufferAlert
{
    public function __construct(
        private readonly SupplierBufferService $buffers,
        private readonly BackupFailureAlerter $alerter,
    ) {}

    public function check(Supplier $supplier): void
    {
        $buffer = $this->buffers->assess($supplier);

        if ($buffer === null || ! $buffer->isShort()) {
            if ($supplier->low_balance_alerted_at !== null) {
                Supplier::query()->whereKey($supplier->id)->update(['low_balance_alerted_at' => null]);
            }

            return;
        }

        $todayStart = CarbonImmutable::now(MalaysiaTime::TIMEZONE)->startOfDay()->utc();
        $claimed = Supplier::query()->whereKey($supplier->id)
            ->where(fn ($q) => $q->whereNull('low_balance_alerted_at')->orWhere('low_balance_alerted_at', '<', $todayStart))
            ->update(['low_balance_alerted_at' => now()]) === 1;

        if ($claimed) {
            Log::warning('Supplier balance is below its buffer', ['supplier' => $supplier->slug, ...$buffer->toArray()]);
            $this->alerter->alert("{$supplier->name} balance is too low", $this->message($supplier, $buffer), 'Supplier balance');
        }
    }

    private function message(Supplier $supplier, SupplierBuffer $buffer): string
    {
        $c = $supplier->currency;
        $amount = fn (float $v, ?float $myr) => "{$c} ".number_format($v, $c === 'IDR' ? 0 : 2).($myr === null || $c === 'MYR' ? '' : ' (about RM '.number_format($myr, 2).')');
        $until = $buffer->coversUntil->format('l j M');
        $why = $buffer->floor !== null && $buffer->required <= $buffer->floor
            ? 'your low-balance threshold'
            : "the busiest {$buffer->windowDays} days of the last 60 ({$buffer->busiestFrom?->format('j M')} – {$buffer->busiestTo?->format('j M')})";

        return "{$supplier->name} holds {$amount($buffer->balance, $buffer->balanceMyr)}. "
            ."To last until the end of {$until} (Malaysia time) it needs {$amount($buffer->required, $buffer->requiredMyr)}, set by {$why}. "
            .'Top up now. Details: Admin → Balance.';
    }
}
