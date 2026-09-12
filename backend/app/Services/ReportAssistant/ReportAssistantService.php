<?php

namespace App\Services\ReportAssistant;

use App\Models\AdminUser;
use App\Models\ReportAssistantAuditLog;
use App\Services\Report\ReportService;
use App\Services\ReportAssistant\Gemini\GeminiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * ADR-087 — orchestrates a single question through the assistant's two-
 * turn Gemini exchange, the SqlGuard-checked query against the curated
 * views, and the audit log (decision 8). Chat history is entirely
 * caller-supplied per request (decision 7: session-scoped only, never
 * persisted server-side) — this service holds no state between calls.
 *
 * Flow:
 *  1. "Planning" turn — Gemini sees the schema + conversation history
 *     and replies with structured JSON: either a SELECT to run, or a
 *     direct answer for a question that needs no data (pure strategy
 *     talk, or a follow-up already answerable from history).
 *  2. If a query was planned, it's SqlGuard-sanitized and run on the
 *     read-only `report_assistant` connection; a rejected/failed query
 *     is reported back to Gemini as an error, never silently retried
 *     with a broadened scope.
 *  3. "Answering" turn — Gemini sees the actual query result (or the
 *     direct answer from step 1) and writes the reply, per decision 5's
 *     grounding policy: every data claim must trace to what was
 *     actually queried, opinion/strategy may draw on outside knowledge
 *     but must read as separate from the data-sourced part.
 */
final class ReportAssistantService
{
    private const ROW_LIMIT = 200;

    /** Audit log keeps only a sample of the result set, not the full rows. */
    private const AUDIT_SAMPLE_ROWS = 20;

    private const SCHEMA_DESCRIPTION = <<<'SCHEMA'
        You may query ONLY these three read-only SQL views. No other table exists to you.

        llm_report_orders — one row per successfully PAID order (already filtered to
        is_test=false, payment_status=paid, paid_at IS NOT NULL). Money columns are
        integer sen (divide by 100 for Ringgit). Never sum final_amount alongside
        platform_profit/affiliate_profit from a JOIN — they are already correct,
        per-order columns here, pre-computed from the ledger so you cannot
        double-count them; just aggregate the columns directly.
          - order_id, order_number, paid_at_kl (Asia/Kuala_Lumpur datetime)
          - customer_email, customer_phone
          - game_id, game_name, package_id, package_name
          - payment_method (e.g. fpx)
          - pricing_basis ('member' or 'standard')
          - delivery_status ('delivered', 'failed', 'processing', 'not_started', ...)
          - affiliate_id, affiliate_name (the whitelabel storefront brand; null = primary brand)
          - wallet_reseller_id, reseller_name (a prepaid-wallet Reseller order; null = not one)
          - supplier_id, supplier_name (which supplier fulfilled this order)
          - cost_price (what this order cost the platform, sen, AS OF WHEN IT WAS PLACED — use
            llm_report_catalog instead for today's cost, they can legitimately differ)
          - transaction_fee, voucher_discount (sen)
          - affiliate_markup_pct, wholesale_markup_pct (percent, whichever channel applied)
          - final_amount (what the customer paid, sen)
          - normal_selling_price (member order's counterfactual standard price, sen; null for standard orders)
          - selling_price (sen)
          - platform_profit, affiliate_profit (ledger-recognized, sen; 0 if not yet delivered)
          Gross margin on one order = final_amount - cost_price - transaction_fee.

        llm_report_membership_fees — one row per membership subscription/renewal fee
        actually booked (ledger type=membership_fee).
          - fee_id, created_at_kl (Asia/Kuala_Lumpur datetime), amount (sen)
          - membership_id, affiliate_id, affiliate_name
          - member_email, plan_id, plan_name

        llm_report_catalog — one row per Package, CURRENT catalog state (not historical —
        a package's cost/price can change over time via the weekly price sync; this is
        "as of right now", not "as of any particular past order"). Use this for "what
        does X cost today" / "which supplier" / "what's our current markup" questions;
        use llm_report_orders' own cost_price for a specific past order's actual margin.
          - package_id, package_name, denomination, is_active, sort_order
          - cost_price, standard_selling_price, markup_percent (sen/percent, current)
          - game_id, game_name, category
          - supplier_id, supplier_name
          - raw_price, raw_currency (the supplier's own listed price/currency before
            conversion to MYR sen; null if this package isn't matched to a synced
            supplier product)

        Rules: SELECT statements only (a leading WITH/CTE is fine), single statement, no
        other table/view name may appear anywhere in the query (including subqueries or
        CTEs). A LIMIT is added automatically if you omit one; don't rely on being able
        to fetch more than a couple hundred rows — aggregate in SQL (GROUP BY/SUM/COUNT),
        don't ask for raw rows to aggregate yourself.
        SCHEMA;

    /**
     * Found live 2026-09-12: without this, Gemini defaulted to
     * PostgreSQL-flavored SQL (`date_trunc()`, `AT TIME ZONE`) that
     * doesn't exist on either engine this app actually runs on — the
     * query failed at execution, not because of missing/wrong data.
     * Telling it the real dialect + today's date up front lets it use
     * plain datetime-string comparisons on `paid_at_kl`/`created_at_kl`
     * (already pre-converted to Asia/Kuala_Lumpur) instead of reaching
     * for a date-truncation function at all.
     */
    private function dialectNote(string $driver): string
    {
        $today = now(ReportService::TIMEZONE)->toDateTimeString();
        $engine = $driver === 'sqlite' ? 'SQLite' : 'MySQL';

        return "Today's date/time in Asia/Kuala_Lumpur is {$today}. SQL dialect: {$engine}. "
            .'Do NOT use date_trunc(), EXTRACT(), or "AT TIME ZONE" — none of those exist here '
            .'(that is PostgreSQL syntax). paid_at_kl/created_at_kl are already plain datetime '
            .'values in Asia/Kuala_Lumpur — filter "this month"/"today"/a date range with plain '
            .'comparisons against a literal datetime string (e.g. paid_at_kl >= \'2026-09-01 00:00:00\'), '
            .'computed from the date given above, rather than a date-truncation function.';
    }

    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly SqlGuard $guard,
        private readonly string $connection = 'report_assistant',
    ) {}

    /**
     * @param  array<int, array{question: string, answer: string}>  $history
     * @return array{answer: string, sql: ?string, row_count: ?int}
     */
    public function ask(AdminUser $admin, string $question, array $history): array
    {
        $turns = $this->buildHistoryTurns($history, $question);

        $plan = $this->plan($turns);

        $sql = null;
        $rows = [];
        $rowCount = null;
        $queryError = null;

        if (($plan['needs_query'] ?? false) === true && ! empty($plan['sql'])) {
            try {
                $sql = $this->guard->sanitize((string) $plan['sql'], self::ROW_LIMIT);
                $rows = array_map(fn ($row) => (array) $row, DB::connection($this->connectionName())->select($sql));
                $rowCount = count($rows);
            } catch (UnsafeSqlException $e) {
                $queryError = $e->getMessage();
            } catch (Throwable $e) {
                Log::warning('report-assistant.query_failed', ['sql' => $sql, 'error' => $e->getMessage()]);
                $queryError = 'The query could not be run against the data.';
            }
        }

        $answer = $this->answer($question, $plan, $rows, $queryError);

        ReportAssistantAuditLog::query()->create([
            'admin_user_id' => $admin->id,
            'question' => $question,
            'generated_sql' => $sql,
            'row_count' => $rowCount,
            'result_sample' => $rows === [] ? null : array_slice($rows, 0, self::AUDIT_SAMPLE_ROWS),
            'answer' => Str::limit($answer, 10000, ''),
            'error' => $queryError,
        ]);

        return ['answer' => $answer, 'sql' => $sql, 'row_count' => $rowCount];
    }

    /**
     * The dedicated `report_assistant` connection (decision 1's read-
     * only DB-credential backstop) only meaningfully differs from the
     * app's own connection on MySQL — sqlite has no user-based GRANTs,
     * and a second `sqlite ':memory:'` connection is actually a
     * *different*, empty in-memory database from the app's own (each
     * `:memory:` handle is isolated), which would silently see none of
     * the app's data. On sqlite (the fast test suite, and any local dev
     * setup still on sqlite), fall back to the app's default connection
     * — SqlGuard's parse-check is the only guardrail there, same as it
     * always is defense-in-depth alongside decision 1's credential.
     */
    private function connectionName(): string
    {
        return config('database.default') === 'sqlite' ? 'sqlite' : $this->connection;
    }

    /**
     * @param  array<int, array{role: 'user'|'model', text: string}>  $turns
     * @return array{needs_query?: bool, sql?: string, direct_answer?: string}
     */
    private function plan(array $turns): array
    {
        $driver = DB::connection($this->connectionName())->getDriverName();

        $systemPrompt = self::SCHEMA_DESCRIPTION."\n\n".$this->dialectNote($driver)."\n\n"
            .'You are the planning step of a two-step pipeline for a business-reports '
            .'assistant. Given the conversation, reply with ONLY a JSON object: '
            .'{"needs_query": boolean, "sql": string|null, "direct_answer": string|null}. '
            .'Set needs_query=true and give a single SELECT in "sql" whenever the '
            .'question asks about actual sales/profit/order/membership-fee data — even '
            .'a follow-up. Set needs_query=false and give "direct_answer" only for pure '
            .'marketing/strategy discussion needing no figures, or something already '
            .'fully answered earlier in this conversation. Never invent a figure in '
            .'"direct_answer" — if a number is needed, query for it instead.';

        $raw = $this->gemini->generate($systemPrompt, $turns, 'application/json');
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['needs_query' => false, 'direct_answer' => null];
    }

    /**
     * @param  array<int, array{needs_query?: bool, sql?: string, direct_answer?: string}>  $plan
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function answer(string $question, array $plan, array $rows, ?string $queryError): string
    {
        $systemPrompt = 'You are a business-reports assistant for a game top-up '
            .'reseller platform. GROUNDING POLICY: every number, ranking, or trend you '
            .'state must come from the query result given to you below — never present '
            .'a figure you were not actually given. You may add general marketing/'
            .'business-strategy knowledge on top of the data, but make clear that part '
            .'is your own reasoning/advice, not something read from the database. '
            .'Reply in the same language the admin is writing in. Money figures below '
            .'are in sen — convert to Ringgit (divide by 100) when you state them.';

        $context = match (true) {
            $queryError !== null => "The planned query failed: {$queryError}. Tell the admin plainly that this question couldn't be answered from the data and why, without guessing a number.",
            $rows !== [] => 'Query result (JSON rows): '.json_encode($rows, JSON_UNESCAPED_UNICODE),
            ! empty($plan['sql']) => 'The query returned no rows — say so plainly rather than inventing a figure.',
            default => 'No query was needed for this turn. Direct note: '.($plan['direct_answer'] ?? ''),
        };

        $turns = [
            ['role' => 'user', 'text' => $question],
            ['role' => 'user', 'text' => $context],
        ];

        return trim($this->gemini->generate($systemPrompt, $turns));
    }

    /**
     * @param  array<int, array{question: string, answer: string}>  $history
     * @return array<int, array{role: 'user'|'model', text: string}>
     */
    private function buildHistoryTurns(array $history, string $question): array
    {
        $turns = [];

        foreach ($history as $turn) {
            $turns[] = ['role' => 'user', 'text' => (string) ($turn['question'] ?? '')];
            $turns[] = ['role' => 'model', 'text' => (string) ($turn['answer'] ?? '')];
        }

        $turns[] = ['role' => 'user', 'text' => $question];

        return $turns;
    }
}
