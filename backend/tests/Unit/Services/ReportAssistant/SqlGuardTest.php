<?php

namespace Tests\Unit\Services\ReportAssistant;

use App\Services\ReportAssistant\SqlGuard;
use App\Services\ReportAssistant\UnsafeSqlException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_allows_a_select_against_the_catalog_view(): void
    {
        $sql = $this->guard->sanitize('SELECT package_name, cost_price FROM llm_report_catalog', 200);

        $this->assertStringContainsString('llm_report_catalog', $sql);
    }

    /**
     * Found live 2026-09-12: Gemini reasonably reached for a CTE
     * ("top game, then its cost/supplier") and the guard rejected it
     * outright since it only recognized a bare leading SELECT.
     */
    public function test_allows_a_select_with_a_leading_cte(): void
    {
        $sql = $this->guard->sanitize(
            'WITH top AS (SELECT game_id FROM llm_report_orders GROUP BY game_id ORDER BY COUNT(*) DESC LIMIT 1) '
            .'SELECT o.game_name, o.cost_price FROM llm_report_orders o JOIN top t ON t.game_id = o.game_id',
            200,
        );

        $this->assertStringContainsString('WITH top AS', $sql);
        $this->assertStringContainsString('LIMIT 200', $sql);
    }

    public function test_rejects_a_cte_hiding_a_delete(): void
    {
        // Every table referenced (llm_report_orders) is whitelisted —
        // isolates that it's the DELETE keyword itself being caught,
        // not the table check.
        $this->expectException(UnsafeSqlException::class);
        $this->guard->sanitize(
            'WITH x AS (SELECT id FROM llm_report_orders) DELETE FROM llm_report_orders WHERE id IN (SELECT id FROM x)',
            200,
        );
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

    /**
     * Wave 3 S-2 (2026-09-29 audit): every one of these reaches a raw
     * table without a `FROM <table>`/`JOIN <table>` shape the old regex
     * recognised. Each pairs the bypass with a legitimate curated view so
     * the "has a FROM" check alone can't be what rejects it.
     *
     * @return array<string, array{string}>
     */
    public static function tableReferenceBypasses(): array
    {
        return [
            'comma join' => ['SELECT a.password FROM llm_report_orders o, admin_users a'],
            'comma join with alias AS' => ['SELECT a.password FROM llm_report_orders AS o, admin_users a'],
            'comma join after a derived table' => ['SELECT a.password FROM (SELECT id FROM llm_report_orders) t, admin_users a'],
            'parenthesised table' => ['SELECT o.order_number FROM llm_report_orders o JOIN (admin_users) a ON 1=1'],
            'STRAIGHT_JOIN' => ['SELECT a.password FROM llm_report_orders o STRAIGHT_JOIN admin_users a'],
            'backtick with no space' => ['SELECT * FROM llm_report_orders WHERE 1 IN (SELECT id FROM`admin_users`)'],
            'block comment as separator' => ['SELECT * FROM llm_report_orders WHERE 1 IN (SELECT id FROM/**/admin_users)'],
            'TABLE statement in a subquery' => ['SELECT * FROM llm_report_orders WHERE id IN (TABLE admin_users)'],
        ];
    }

    #[DataProvider('tableReferenceBypasses')]
    public function test_rejects_a_disallowed_table_reached_without_a_plain_from_or_join(string $sql): void
    {
        $this->expectException(UnsafeSqlException::class);
        $this->guard->sanitize($sql, 200);
    }

    public function test_still_allows_a_derived_table_subquery(): void
    {
        $sql = $this->guard->sanitize(
            'SELECT t.game_name FROM (SELECT game_name FROM llm_report_orders) t JOIN llm_report_catalog c ON c.game_name = t.game_name',
            200,
        );

        $this->assertStringContainsString('LIMIT 200', $sql);
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
