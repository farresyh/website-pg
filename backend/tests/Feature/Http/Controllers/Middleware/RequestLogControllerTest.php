<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\Supplier;
use App\Models\SupplierRequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-051 (MUI-9) — read-only viewer over supplier_request_logs.
 */
class RequestLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
    }

    private function log(array $overrides = []): SupplierRequestLog
    {
        return SupplierRequestLog::query()->create(array_merge([
            'call_type' => 'checkBalance',
            'method' => 'POST',
            'url' => 'https://api.gamevion.com/api/check-balance',
            'status_code' => 200,
            'outcome' => 'success',
        ], $overrides));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/middleware/request-logs')->assertForbidden();
    }

    public function test_index_lists_rows_most_recent_first(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier();
        $older = $this->log(['supplier_id' => $supplier->id])->forceFill(['created_at' => now()->subHour()]);
        $older->save();
        $newer = $this->log(['supplier_id' => $supplier->id]);

        $body = $this->getJson('/api/middleware/request-logs')->assertOk()->json();

        $this->assertSame($newer->id, $body['data'][0]['id']);
        $this->assertSame($older->id, $body['data'][1]['id']);
        $this->assertSame('Gamevion', $body['data'][0]['supplier']['name']);
    }

    public function test_index_filters_by_supplier_call_type_and_outcome(): void
    {
        $this->actingAsAdmin();
        $supplier = $this->supplier();
        $match = $this->log(['supplier_id' => $supplier->id, 'call_type' => 'createOrder', 'outcome' => 'failure']);
        $this->log(['supplier_id' => $supplier->id, 'call_type' => 'checkBalance', 'outcome' => 'success']);

        $body = $this->getJson('/api/middleware/request-logs?'.http_build_query([
            'supplier_id' => $supplier->id,
            'call_type' => 'createOrder',
            'outcome' => 'failure',
        ]))->assertOk()->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame($match->id, $body['data'][0]['id']);
    }

    public function test_index_date_filter_uses_malaysia_days(): void
    {
        $this->actingAsAdmin();
        $at = fn (string $utc) => tap($this->log(), fn ($row) => $row->forceFill(['created_at' => $utc])->save());
        $at('2026-10-12 15:59:59'); // 12 Oct 23:59:59 MYT
        $first = $at('2026-10-12 16:00:00'); // 13 Oct 00:00 MYT
        $last = $at('2026-10-13 15:59:59'); // 13 Oct 23:59:59 MYT
        $at('2026-10-13 16:00:00'); // 14 Oct 00:00 MYT

        $ids = collect($this->getJson('/api/middleware/request-logs?from=2026-10-13&to=2026-10-13')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$first->id, $last->id], $ids);
    }

    public function test_index_rejects_a_non_date_filter(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/middleware/request-logs?from=yesterday')->assertUnprocessable();
    }

    public function test_show_returns_the_stored_already_redacted_payload_unchanged(): void
    {
        $this->actingAsAdmin();
        $row = $this->log([
            'request_payload' => ['headers' => ['Authorization' => ['[REDACTED]']], 'body' => null],
        ]);

        $body = $this->getJson("/api/middleware/request-logs/{$row->id}")->assertOk()->json();

        $this->assertSame(['[REDACTED]'], $body['request_payload']['headers']['Authorization']);
    }
}
