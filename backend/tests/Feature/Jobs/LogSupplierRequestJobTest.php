<?php

namespace Tests\Feature\Jobs;

use App\Jobs\LogSupplierRequestJob;
use App\Models\Supplier;
use App\Models\SupplierRequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-051 — the actual DB write, resolving supplier_id by slug so the
 * adapter-side context tag can stay just the slug string.
 */
class LogSupplierRequestJobTest extends TestCase
{
    use RefreshDatabase;

    private function entry(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'gamevion',
            'call_type' => 'checkBalance',
            'order_id' => null,
            'method' => 'POST',
            'url' => 'https://api.gamevion.com/api/check-balance',
            'status_code' => 200,
            'outcome' => 'success',
            'duration_ms' => 42,
            'request_payload' => ['headers' => ['Authorization' => ['[REDACTED]']], 'body' => null],
            'response_payload' => ['data' => ['user_balance' => 5000]],
            'error_message' => null,
        ], $overrides);
    }

    public function test_resolves_supplier_id_by_slug(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);

        (new LogSupplierRequestJob($this->entry()))->handle();

        $row = SupplierRequestLog::query()->sole();
        $this->assertSame($supplier->id, $row->supplier_id);
        $this->assertSame('checkBalance', $row->call_type);
        $this->assertSame('success', $row->outcome);
        $this->assertSame(42, $row->duration_ms);
        $this->assertSame(['data' => ['user_balance' => 5000]], $row->response_payload);
    }

    public function test_writes_a_null_supplier_id_when_no_matching_supplier_row_exists(): void
    {
        (new LogSupplierRequestJob($this->entry(['slug' => 'no-such-supplier'])))->handle();

        $row = SupplierRequestLog::query()->sole();
        $this->assertNull($row->supplier_id);
    }

    public function test_writes_a_synthetic_breaker_skip_row_with_null_method_and_url(): void
    {
        (new LogSupplierRequestJob($this->entry([
            'method' => null,
            'url' => null,
            'status_code' => null,
            'outcome' => 'skipped_breaker_open',
            'duration_ms' => null,
            'request_payload' => null,
            'response_payload' => null,
            'error_message' => 'Circuit breaker open — call was never sent.',
        ])))->handle();

        $row = SupplierRequestLog::query()->sole();
        $this->assertSame('skipped_breaker_open', $row->outcome);
        $this->assertNull($row->method);
        $this->assertNull($row->url);
    }
}
