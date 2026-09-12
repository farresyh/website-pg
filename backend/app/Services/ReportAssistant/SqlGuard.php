<?php

namespace App\Services\ReportAssistant;

/**
 * ADR-087 decision 2 — statement guardrails, enforced in application
 * code before any Gemini-generated query ever reaches the database.
 * Defense-in-depth on top of decision 1's read-only `report_assistant`
 * DB connection (config/database.php), which is the hard backstop if
 * this check is ever bypassed — not either/or.
 *
 * This is a regex-based parse-check, not a full SQL parser (no such
 * dependency exists in this codebase) — good enough to reject the
 * shapes that matter: multi-statement, non-SELECT, and any table/view
 * outside the curated set. It is deliberately conservative: anything
 * it can't confidently classify as safe, it rejects rather than lets
 * through.
 */
final class SqlGuard
{
    /**
     * The ONLY tables/views a generated query may reference — must
     * match exactly the views created by the
     * create_llm_report_views migration (ADR-087 decision 1/3). Never
     * add a raw table here.
     */
    public const ALLOWED_TABLES = ['llm_report_orders', 'llm_report_membership_fees', 'llm_report_catalog'];

    /**
     * Keywords that have no legitimate place in a read-only, single-
     * SELECT report query. Checked as whole-word matches against the
     * uppercased statement — not exhaustive SQL-injection coverage
     * (the read-only DB credential is what actually prevents damage if
     * one of these slips through), but catches the query shapes a
     * text-to-SQL model could plausibly emit by mistake or by a crafted
     * prompt-injection payload in view data.
     */
    private const FORBIDDEN_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE',
        'GRANT', 'REVOKE', 'ATTACH', 'DETACH', 'PRAGMA', 'REPLACE', 'MERGE',
        'CALL', 'EXEC', 'EXECUTE', 'VACUUM', 'LOAD_FILE', 'OUTFILE', 'DUMPFILE',
        'INFORMATION_SCHEMA', 'SLEEP', 'BENCHMARK',
    ];

    /**
     * Validates `$sql` and returns it with the row-limit cap applied
     * (decision 2's result-row-limit guardrail). Throws
     * UnsafeSqlException on any violation — never silently repairs an
     * unsafe query into a safe one, only ever adds/lowers a LIMIT.
     */
    public function sanitize(string $sql, int $rowLimit): string
    {
        $trimmed = trim($sql);
        $trimmed = rtrim($trimmed, "; \t\n\r\0\x0B");

        if ($trimmed === '') {
            throw new UnsafeSqlException('Empty query.');
        }

        if (str_contains($trimmed, ';')) {
            throw new UnsafeSqlException('Only a single SQL statement is allowed.');
        }

        // A leading WITH (a CTE) is allowed alongside a bare SELECT —
        // found live 2026-09-12: Gemini reasonably reaches for
        // `WITH top AS (...) SELECT ...` for a "top X, then look up its
        // Y" question, and rejecting it outright pushed it to give up
        // rather than write an equivalent subquery. Safe to allow: MySQL
        // permits `WITH ... UPDATE/DELETE` too, but those keywords are
        // still caught by the forbidden-keyword scan below regardless of
        // where in the statement they appear — this check only relaxes
        // which *opening* keyword is acceptable, not what's allowed
        // after it.
        if (! preg_match('/^(?:with|select)\s/i', $trimmed)) {
            throw new UnsafeSqlException('Only SELECT statements (optionally with a leading WITH/CTE clause) are allowed.');
        }

        $upper = strtoupper($trimmed);
        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/', $upper)) {
                throw new UnsafeSqlException("Query contains a disallowed keyword: {$keyword}.");
            }
        }

        if (! preg_match_all('/\b(?:FROM|JOIN)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $trimmed, $matches)) {
            throw new UnsafeSqlException('Query has no FROM clause.');
        }

        // A CTE's own name (`WITH top AS (...)`) is referenced later via
        // FROM/JOIN exactly like a real table — it isn't one, so it must
        // be allowed alongside the curated views for THIS query, without
        // widening ALLOWED_TABLES itself (a CTE alias has no access to
        // anything the rest of the query couldn't already reach).
        $allowedForThisQuery = [...self::ALLOWED_TABLES, ...$this->cteNames($trimmed)];

        foreach ($matches[1] as $table) {
            if (! in_array(strtolower($table), $allowedForThisQuery, true)) {
                throw new UnsafeSqlException("Query references a table that isn't allowed: {$table}.");
            }
        }

        return $this->capRowLimit($trimmed, $rowLimit);
    }

    /**
     * @return array<int, string>
     */
    private function cteNames(string $sql): array
    {
        if (! preg_match('/^with\s/i', $sql)) {
            return [];
        }

        preg_match_all('/(?:^with|,)\s*`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+as\s*\(/i', $sql, $matches);

        return array_map('strtolower', $matches[1]);
    }

    private function capRowLimit(string $sql, int $rowLimit): string
    {
        if (preg_match('/\blimit\s+(\d+)\s*(?:,\s*\d+)?\s*$/i', $sql, $limitMatch)) {
            if ((int) $limitMatch[1] <= $rowLimit) {
                return $sql;
            }

            return preg_replace('/\blimit\s+\d+\s*(?:,\s*\d+)?\s*$/i', "LIMIT {$rowLimit}", $sql);
        }

        return "{$sql} LIMIT {$rowLimit}";
    }
}
