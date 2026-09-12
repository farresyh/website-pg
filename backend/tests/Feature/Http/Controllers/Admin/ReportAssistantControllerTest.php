<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\ReportAssistantAuditLog;
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
