<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-073 decision 3(b): the optional receipt/proof file attached to a
 * manual `Reseller` (wallet) top-up. Linked from its `ledger_entries` row
 * via `reference_type = 'wallet_topup_receipt'` / `reference_id` — this
 * model holds no `owner_type`/`owner_id` of its own, the ledger entry is
 * the tenant-scoped record of truth.
 */
class WalletTopupReceipt extends Model
{
    protected $fillable = [
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];
}
