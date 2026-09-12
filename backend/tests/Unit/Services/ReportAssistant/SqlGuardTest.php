<?php

namespace Tests\Unit\Services\ReportAssistant;

use App\Services\ReportAssistant\SqlGuard;
use App\Services\ReportAssistant\UnsafeSqlException;
use Tests\TestCase;

/**
 * ADR-087 decision 2 — locks in the exact guardrail behaviors: reject
 * non-SELECT, reject multi-statement, reject any table outside the
 * curated view set, and cap the result row limit rather than trust
 * whatever LIMIT (if any) Gemini generated.
 */
class SqlGuardTest extends TestCase
{
    private SqlGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new SqlGuard;
    }

    public function test_allows_a_select_against_an_allowed_view(): void
    {
        $sql = $this->guard->sanitize('SELECT game_name, SUM(final_amount) FROM llm_report_orders GROUP BY game_name', 200);

        $this->assertStringContainsString('llm_report_orders', $sql);
        $this->assertStringContainsString('LIMIT 200', $sql);
    }

    public function test_rejects_non_select_statements(): void
    {
        $this->expectException(UnsafeSqlException::class);
        $this->guard->sanitize('DELETE FROM llm_report_orders', 200);
    }

    public function test_rejects_multiple_statements(): void
    {
        $this->expectException(UnsafeSqlException::class);
        $this->guard->sanitize('SELECT 1 FROM llm_report_orders; DROP TABLE orders', 200);
    }

    public function test_rejects_a_table_outside_the_curated_views(): void
    {
        $this->expectException(UnsafeSqlException::class);
        $this->guard->sanitize('SELECT password FROM admin_users', 200);
    }

    public function test_rejects_a_disallowed_table_reached_via_a_join(): void
    {
        $this->expectException(UnsafeSqlException::class);
        $this->guard->sanitize(
            'SELECT o.order_number FROM llm_report_orders o JOIN admin_users a ON a.id = 1',
            200,
        );
    }

    public function test_rejects_a_disallowed_table_hidden_in_a_subquery(): void
    {
        $this->expectException(UnsafeSqlException::class);
        $this->guard->sanitize(
            'SELECT * FROM llm_report_orders WHERE order_id IN (SELECT id FROM admin_users)',
            200,
        );
    }

    public function test_rejects_a_forbidden_keyword_even_inside_a_select(): void
    {
        $this->expectException(UnsafeSqlException::class);
        $this->guard->sanitize('SELECT * FROM llm_report_orders o; CALL some_proc()', 200);
    }

    public function test_lowers_an_oversized_requested_limit(): void
    {
        $sql = $this->guard->sanitize('SELECT * FROM llm_report_orders LIMIT 5000', 200);

        $this->assertStringContainsString('LIMIT 200', $sql);
        $this->assertStringNotContainsString('5000', $sql);
    }

    public function test_keeps_a_requested_limit_under_the_cap(): void
    {
        $sql = $this->guard->sanitize('SELECT * FROM llm_report_orders LIMIT 10', 200);

        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function test_rejects_empty_query(): void
    {
        $this->expectException(UnsafeSqlException::class);
        $this->guard->sanitize('   ', 200);
    }
}
