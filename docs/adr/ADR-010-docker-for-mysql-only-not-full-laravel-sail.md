# ADR-010: Docker for MySQL only, not full Laravel Sail

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted — 2026-07-23

**Decision:** `backend/docker-compose.yml` runs a single MySQL 8.4 service, nothing else. PHP continues to run natively via Laravel Herd (already working). A dedicated PHPUnit config, `phpunit.concurrency.xml`, points only the small set of tests that need real row-level locking at this dockerized MySQL; the default `phpunit.xml` suite keeps using sqlite `:memory:` for speed.

**Rationale:** SQLite locks at the file level, not per-row, so it can't faithfully prove the ledger/voucher locking guarantees (ADR-002 addendum) — those specific tests need real MySQL `SELECT ... FOR UPDATE` semantics. Laravel Sail was considered but rejected for this stage: Sail also containerizes PHP, which would duplicate/conflict with the already-working native Herd setup for no added benefit — the only real gap was "we have no database server," not "we need a full containerized stack." Keeping Docker's footprint to just the one MySQL service is the minimal fix for the actual gap.

**Consequence to track:** concurrency-sensitive tests live in `tests/Concurrency/` and require `docker compose up -d` first; they are intentionally excluded from the default `php artisan test` run so day-to-day development never depends on Docker being up. Revisit if/when the project needs Sail's broader containerization (e.g. matching a containerized production deploy) — not needed for MVP.
