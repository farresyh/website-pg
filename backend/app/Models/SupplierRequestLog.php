<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-051 (MID-10/11, MUI-9) — one row per outbound supplier-adapter
 * HTTP call, written by LogSupplierRequestJob from an already-redacted
 * payload (SupplierRequestPayloadRedactor runs before the row is ever
 * queued, per foundation-security.md §7's "redact before writing"
 * requirement — never in this model).
 */
class SupplierRequestLog extends Model
{
    protected $fillable = [
        'supplier_id',
        'call_type',
        'order_id',
        'method',
        'url',
        'status_code',
        'outcome',
        'duration_ms',
        'request_payload',
        'response_payload',
        'error_message',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
