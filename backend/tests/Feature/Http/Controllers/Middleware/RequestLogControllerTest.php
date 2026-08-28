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
