<?php

namespace App\Services\Reseller;

use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;

/**
 * ADR-131 decisions 8-11: the data of one Payment Receipt, rendered on
 * demand from immutable facts. One receipt per paid CHIP top-up
 * (`PB-<attempt reference>`) and per manual admin credit
 * (`PB-M-<ledger entry id>`). Always scoped to the asking reseller: a
 * number that belongs to someone else is "not found", same as one that
 * does not exist.
 *
 * The fee is what the reseller bore (`total_charged_sen - amount_sen`),
 * never CHIP's reported `gateway_fee_sen`: that is the platform's cost
 * and platform cost structure stays private.
 */
final class ResellerWalletReceiptService
{
    /** The receipt number a ledger row is listed under, or null when the row has none. */
    public static function numberFor(LedgerEntry $entry, ?string $attemptReference): ?string
    {
        if ($entry->type !== 'wallet_topup') {
            return null;
        }

        if ($entry->reference_type === 'wallet_topup_attempt') {
            return $attemptReference === null ? null : 'PB-'.$attemptReference;
        }

        return 'PB-M-'.$entry->id;
    }

    /** @return array<string, mixed>|null */
    public function forNumber(Reseller $reseller, string $number): ?array
    {
        $data = preg_match('/^PB-M-(\d+)$/', $number, $m) === 1
            ? $this->manualCredit($reseller, (int) $m[1])
            : (preg_match('/^PB-(WT-[A-Z0-9]+)$/', $number, $m) === 1 ? $this->chipTopup($reseller, $m[1]) : null);

        if ($data === null) {
            return null;
        }

        $settings = PlatformSettings::current();

        return $data + [
            'number' => $number,
            'reseller_name' => $reseller->business_name,
            'bill_to' => array_filter([
                'name' => $reseller->business_name,
                'contact' => $reseller->contact_name,
                'email' => $reseller->email,
            ]),
            'seller' => [
                'name' => $settings->company_legal_name,
                'reg_no' => $settings->company_reg_no,
                'address' => $settings->company_address,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function chipTopup(Reseller $reseller, string $reference): ?array
    {
        $attempt = WalletTopupAttempt::query()
            ->with('ledgerCredit')
            ->where('reseller_id', $reseller->id)
            ->where('reference', $reference)
            ->where('status', WalletTopupAttemptStatus::Paid->value)
            ->first();

        if ($attempt === null || $attempt->ledgerCredit === null) {
            return null;
        }

        return [
            'issued_at' => $attempt->ledgerCredit->created_at->setTimezone('Asia/Kuala_Lumpur'),
            'amount_sen' => $attempt->amount_sen,
            'fee_sen' => $attempt->total_charged_sen - $attempt->amount_sen,
            'total_sen' => $attempt->total_charged_sen,
            'paid_via' => PaymentMethod::query()->where('channel_code', $attempt->channel_code)->value('label') ?? 'Online payment',
        ];
    }

    /** @return array<string, mixed>|null */
    private function manualCredit(Reseller $reseller, int $entryId): ?array
    {
        $entry = LedgerEntry::query()
            ->where('id', $entryId)
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
            ->where('owner_id', $reseller->id)
            ->where('type', 'wallet_topup')
            ->where(fn ($q) => $q->whereNull('reference_type')->orWhere('reference_type', '!=', 'wallet_topup_attempt'))
            ->first();

        if ($entry === null) {
            return null;
        }

        return [
            'issued_at' => $entry->created_at->setTimezone('Asia/Kuala_Lumpur'),
            'amount_sen' => $entry->amount,
            'fee_sen' => 0,
            'total_sen' => $entry->amount,
            'paid_via' => 'Bank transfer',
        ];
    }
}
