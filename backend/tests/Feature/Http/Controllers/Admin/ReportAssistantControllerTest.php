<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\ReportAssistantAuditLog;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Report\ReportService;
use App\Services\ReportAssistant\Gemini\FakeGeminiClient;
use App\Services\ReportAssistant\Gemini\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-087 — the assistant's route through SqlGuard, the curated
 * `llm_report_orders` view, and the audit log. FakeGeminiClient's
 * canned responses stand in for the two Gemini turns (plan, then
 * answer) so this locks in the pipeline's wiring without a real,
 * billed API call.
 */
class ReportAssistantControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 20,
            'payment_status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
            'delivery_status' => DeliveryStatus::Delivered->value,
            'is_test' => false,
        ], $overrides));
    }

    public function test_ask_requires_authentication(): void
    {
        $this->postJson('/api/reports/assistant/ask', ['question' => 'top game?'])->assertUnauthorized();
    }

    public function test_ask_is_forbidden_for_a_regular_admin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->postJson('/api/reports/assistant/ask', ['question' => 'top game?'])->assertForbidden();
    }

    public function test_ask_runs_a_planned_query_against_the_curated_view_and_grounds_the_answer(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $delivered = $this->order(['final_amount' => 5000, 'platform_profit' => 400, 'affiliate_profit' => 80]);
        (new LedgerService)->credit('platform', null, 400, 'order_profit', 'order', $delivered->id);
        (new LedgerService)->credit('affiliate', null, 80, 'order_profit', 'order', $delivered->id);

        $this->app->instance(GeminiClient::class, new FakeGeminiClient([
            json_encode([
                'needs_query' => true,
                'sql' => 'SELECT COUNT(*) as orders_count, SUM(final_amount) as total_sales FROM llm_report_orders',
            ]),
            'Jumlah jualan ialah RM50.00 daripada 1 pesanan.',
        ]));

        $response = $this->postJson('/api/reports/assistant/ask', [
            'question' => 'Berapa jumlah jualan setakat ini?',
        ]);

        $response->assertOk()->assertJson([
            'answer' => 'Jumlah jualan ialah RM50.00 daripada 1 pesanan.',
            'row_count' => 1,
        ]);
        $this->assertStringContainsString('llm_report_orders', $response->json('sql'));

        $this->assertDatabaseCount('report_assistant_audit_logs', 1);
        $log = ReportAssistantAuditLog::query()->first();
        $this->assertSame($admin->id, $log->admin_user_id);
        $this->assertSame('Berapa jumlah jualan setakat ini?', $log->question);
        $this->assertNotNull($log->generated_sql);
        $this->assertSame(1, $log->row_count);
    }

    /**
     * 2026-09-12 follow-up — the founder asked whether cost/supplier/
     * catalog data was in scope; it wasn't, so `llm_report_orders` was
     * extended with cost_price/supplier columns and a new
     * `llm_report_catalog` view (current package cost/supplier state,
     * kept separate from the per-order historical view on purpose).
     * This locks in that the catalog view is queryable end-to-end.
     */
    public function test_ask_can_query_current_catalog_cost_and_supplier_data(): void
    {
        $this->actingAsSuperAdmin();

        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => []]);
        $package = Package::query()->create([
            'game_id' => $game->id,
            'name' => '278 Diamonds',
            'cost_price' => 3000,
            'standard_selling_price' => 3500,
            'markup_percent' => 16.67,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'GV-278',
        ]);
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'external_ref' => 'GV-278',
            'name' => '278 Diamonds',
            'price_sen' => 3000,
            'raw_price' => 45000,
            'raw_currency' => 'IDR',
            'last_synced_at' => now(),
        ]);

        $this->app->instance(GeminiClient::class, new FakeGeminiClient([
            json_encode([
                'needs_query' => true,
                'sql' => 'SELECT package_name, cost_price, raw_price, raw_currency, supplier_name FROM llm_report_catalog WHERE package_id = '.$package->id,
            ]),
            'Cost sekarang RM30.00, raw price IDR 45000 dari Gamevion.',
        ]));

        $response = $this->postJson('/api/reports/assistant/ask', ['question' => 'apa cost package 278 Diamonds sekarang?']);

        $response->assertOk()->assertJson(['row_count' => 1]);
        $log = ReportAssistantAuditLog::query()->latest('id')->first();
        $this->assertSame(3000, $log->result_sample[0]['cost_price']);
        $this->assertSame('Gamevion', $log->result_sample[0]['supplier_name']);
        $this->assertSame(45000, $log->result_sample[0]['raw_price']);
    }

    /** llm_report_orders' extension (cost_price/supplier_name) queryable end-to-end. */
    public function test_ask_can_query_the_extended_order_level_cost_and_supplier_columns(): void
    {
        $this->actingAsSuperAdmin();

        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => []]);
        $this->order(['final_amount' => 1100, 'cost_price' => 700, 'supplier_id' => $supplier->id]);

        $this->app->instance(GeminiClient::class, new FakeGeminiClient([
            json_encode([
                'needs_query' => true,
                'sql' => 'SELECT supplier_name, SUM(cost_price) as total_cost FROM llm_report_orders GROUP BY supplier_name',
            ]),
            'ok',
        ]));

        $response = $this->postJson('/api/reports/assistant/ask', ['question' => 'berapa jumlah cost ikut supplier?']);

        $response->assertOk()->assertJson(['row_count' => 1]);
        $log = ReportAssistantAuditLog::query()->latest('id')->first();
        $this->assertSame('Digiflazz', $log->result_sample[0]['supplier_name']);
        $this->assertSame(700, $log->result_sample[0]['total_cost']);
    }

    public function test_ask_never_double_counts_sales_across_the_dual_ledger_rows(): void
    {
        $this->actingAsSuperAdmin();

        $delivered = $this->order(['final_amount' => 1100]);
        (new LedgerService)->credit('platform', null, 100, 'order_profit', 'order', $delivered->id);
        (new LedgerService)->credit('affiliate', null, 20, 'order_profit', 'order', $delivered->id);

        $this->app->instance(GeminiClient::class, new FakeGeminiClient([
            json_encode([
                'needs_query' => true,
                'sql' => 'SELECT SUM(final_amount) as total_sales, SUM(platform_profit) as platform_profit FROM llm_report_orders',
            ]),
            'ok',
        ]));

        $response = $this->postJson('/api/reports/assistant/ask', ['question' => 'total sales?']);

        $response->assertOk();
        $this->assertSame(1, $response->json('row_count'));
    }

    public function test_ask_rejects_a_query_outside_the_curated_views_without_running_it(): void
    {
        $this->actingAsSuperAdmin();

        $this->app->instance(GeminiClient::class, new FakeGeminiClient([
            json_encode(['needs_query' => true, 'sql' => 'SELECT password FROM admin_users']),
            'That is not something I can look up.',
        ]));

        $response = $this->postJson('/api/reports/assistant/ask', ['question' => 'show me admin passwords']);

        $response->assertOk()->assertJson(['sql' => null]);
        $log = ReportAssistantAuditLog::query()->first();
        $this->assertNotNull($log->error);
    }

    /**
     * Found live 2026-09-12: without telling Gemini the real SQL dialect
     * + today's date, it defaulted to PostgreSQL-flavored SQL
     * (date_trunc(), AT TIME ZONE) that fails on both engines this app
     * actually runs on. Locks in that the planning-turn prompt always
     * carries the dialect + current KL date.
     */
    public function test_ask_tells_gemini_the_real_sql_dialect_and_todays_date(): void
    {
        $this->actingAsSuperAdmin();

        $fake = new FakeGeminiClient([
            json_encode(['needs_query' => false, 'direct_answer' => null]),
            'ok',
        ]);
        $this->app->instance(GeminiClient::class, $fake);

        $this->postJson('/api/reports/assistant/ask', ['question' => 'top game this month?'])->assertOk();

        $planPrompt = $fake->calls[0]['systemPrompt'];
        $this->assertStringContainsString('SQLite', $planPrompt);
        $this->assertStringContainsString((string) now(ReportService::TIMEZONE)->format('Y-m-d'), $planPrompt);
        $this->assertStringContainsString('date_trunc', $planPrompt);
    }

    public function test_ask_answers_directly_without_a_query_for_pure_strategy_questions(): void
    {
        $this->actingAsSuperAdmin();

        $this->app->instance(GeminiClient::class, new FakeGeminiClient([
            json_encode(['needs_query' => false, 'direct_answer' => null]),
            'Consider bundling top-up packages with limited-time bonuses.',
        ]));

        $response = $this->postJson('/api/reports/assistant/ask', [
            'question' => 'What marketing strategy should we try next month?',
        ]);

        $response->assertOk()->assertJson(['sql' => null, 'row_count' => null]);
    }

    public function test_ask_forwards_history_as_prior_conversation_turns(): void
    {
        $this->actingAsSuperAdmin();

        $fake = new FakeGeminiClient([
            json_encode(['needs_query' => false, 'direct_answer' => 'from history']),
            'Yes, as discussed.',
        ]);
        $this->app->instance(GeminiClient::class, $fake);

        $this->postJson('/api/reports/assistant/ask', [
            'question' => 'macam mana margin dia?',
            'history' => [
                ['question' => 'tunjuk top game bulan ni', 'answer' => 'MLBB adalah game paling laris.'],
            ],
        ])->assertOk();

        $planTurns = $fake->calls[0]['turns'];
        $this->assertSame('user', $planTurns[0]['role']);
        $this->assertSame('tunjuk top game bulan ni', $planTurns[0]['text']);
        $this->assertSame('model', $planTurns[1]['role']);
        $this->assertSame('MLBB adalah game paling laris.', $planTurns[1]['text']);
    }
}
