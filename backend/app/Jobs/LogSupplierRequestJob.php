<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Models\SupplierRequestLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-051 decision 4 — the actual DB write, off the calling adapter's
 * thread entirely (dispatched from SupplierRequestLogger's on_stats
 * callback, which already redacted the payload before this job was
 * ever queued). Resolves `supplier_id` by slug here rather than
 * requiring adapters to know their own Supplier row's id — keeps the
 * adapter-side context tag to just the slug string it already is.
 */
final class LogSupplierRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    /**
     * @param  array{
     *     slug: string,
     *     call_type: string,
     *     order_id: int|null,
     *     method: string|null,
     *     url: string|null,
     *     status_code: int|null,
     *     outcome: string,
     *     duration_ms: int|null,
     *     request_payload: array|null,
     *     response_payload: array|null,
     *     error_message: string|null,
     * }  $entry
     */
    public function __construct(private readonly array $entry)
    {
        // Deliberately its own queue, same reasoning as
        // SyncSupplierPricesJob's 'price-sync' queue (ADR-020 decision
        // #5) — a burst of supplier calls logging themselves must
        // never sit in front of, or behind, an urgent order job.
        $this->onQueue('supplier-request-logs');
    }

    /** @return array<string, mixed> raw constructor payload — used by tests asserting a dispatched job's shape. */
    public function entry(): array
    {
        return $this->entry;
    }

    public function handle(): void
    {
        SupplierRequestLog::query()->create([
            'supplier_id' => Supplier::query()->where('slug', $this->entry['slug'])->value('id'),
            'call_type' => $this->entry['call_type'],
            'order_id' => $this->entry['order_id'],
            'method' => $this->entry['method'],
            'url' => $this->entry['url'],
            'status_code' => $this->entry['status_code'],
            'outcome' => $this->entry['outcome'],
            'duration_ms' => $this->entry['duration_ms'],
            'request_payload' => $this->entry['request_payload'],
            'response_payload' => $this->entry['response_payload'],
            'error_message' => $this->entry['error_message'],
        ]);
    }
}
