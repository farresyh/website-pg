# ADR-117: Prod DB least-privilege — `doadmin` superuser replaced by a dedicated scoped app user

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted & built — live in production 2026-09-30, verified end-to-end (health check, Horizon, a real backup run).

**Context:**

Found 2026-09-29 while provisioning the `report_assistant` connection (ADR-087): the app's main
database connection ran as **`doadmin`**, the DigitalOcean managed-MySQL cluster's own superuser.
`SHOW GRANTS` confirmed `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, CREATE USER,
CREATE VIEW, CREATE ROUTINE, EVENT, TRIGGER ... ON *.* ... WITH GRANT OPTION` — full admin power
over every database on the cluster, plus the ability to create new users and grant them arbitrary
privileges. A SQL-injection-class bug anywhere in the app would hand an attacker that same power,
not just access to this app's own tables. Originally bundled with the old-`pekangame-prod`-droplet
decommission (ADR-114) into one founder-deferred maintenance window (`docs/prd.md` §16 item 52,
2026-09-29); split apart 2026-09-30 once the decommission's own pre-destroy audit gave enough
confidence to treat these as two independent, separately-schedulable changes.

**Pre-build audit (money-critical DB, founder explicitly asked for a solid, no-data-loss-risk
check before touching anything):**
- **Views:** the 3 `llm_report_*` views (ADR-087) are all `SQL SECURITY DEFINER = doadmin@%` —
  they keep running under doadmin's own privileges regardless of which user queries them, as long
  as the `doadmin` account itself still exists. Confirmed via `information_schema.views`.
- **Triggers / stored routines / events:** all zero in `defaultdb`. None of doadmin's
  `EVENT`/`TRIGGER`/`CREATE ROUTINE`/`ALTER ROUTINE` grants are actually exercised by anything.
- **Migration history:** grepped every migration for raw SQL beyond standard DDL. Found a
  recurring `CREATE VIEW`/`DROP VIEW` pattern (the LLM report views were created, redefined, and
  redefined again across three separate migrations) — a future migration doing the same needs
  `CREATE VIEW`/`SHOW VIEW`, so the new user needs those even though no *currently pending*
  migration uses them.
- **Backups (ADR-039/095):** `spatie/laravel-backup` resolves its dump credentials from the same
  `mysql` connection the app uses — a credential switch here also changes what the nightly backup
  runs as. `config/database.php`'s `dump` options confirmed `useSingleTransaction => true` (no
  `LOCK TABLES` needed) and `mysql_gtid_purged => 'OFF'` (no replication privilege needed) — the
  dump only actually needs `SELECT` + `SHOW VIEW`.
- **Precedent already in this codebase:** `report_assistant`@`%` (ADR-087, provisioned
  2026-09-29) is the exact same pattern — a narrowly-scoped MySQL user for a specific purpose,
  with its own documented `CREATE USER`/rollback recipe. Reused that same shape here.

**Decision:**

1. **Create a new user, never touch `doadmin`.** `pekangame_app`@`%`, `GRANT SELECT, INSERT,
   UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW, CREATE TEMPORARY
   TABLES ON defaultdb.* TO 'pekangame_app'@'%'` — no `CREATE USER`, no `GRANT OPTION`, no
   `RELOAD`/`PROCESS`/replication grants, no access outside `defaultdb`. `doadmin` keeps existing,
   unmodified, as a break-glass account — every step of this change is additive (`CREATE USER` +
   `GRANT`) until the final `.env` swap, which is a config change, not a data change, and is
   instantly revertible from a `.env` backup.
2. **Test the new user in isolation before touching the live app.** Five checks run directly
   against the new user, none touching real data: `SELECT` on `orders` (real table), `SELECT` on
   all 3 `llm_report_*` views, a full `CREATE TABLE`+`ALTER`+`CREATE INDEX`+`INSERT`+`DROP` cycle
   on a throwaway table, a denied `SELECT` on `mysql.user` (proves the `defaultdb`-only scope), and
   a denied `CREATE USER` (proves no privilege escalation). All five passed exactly as expected —
   the DDL test's first attempt failed on DO's own `sql_require_primary_key` server setting
   (unrelated to grants; the retry used a table with a primary key and passed clean).
3. **Cutover:** backed up `.env` (`.env.bak-2026-09-30-doadmin-least-privilege`), swapped
   `DB_USERNAME`/`DB_PASSWORD`, `config:cache` + `horizon:terminate`. Verified via `artisan
   tinker`'s `SELECT CURRENT_USER()` (`pekangame_app@%`), `/api/health` (all green), and — the
   real proof — a manual `artisan backup:run --only-db`, which dumped, zipped, verified, and
   uploaded to R2 successfully under the new restricted credentials. php-fpm's own
   `opcache.validate_timestamps=On` (2s revalidate) meant no manual fpm reload was needed either.
4. **Two production-sensitive steps (`CREATE USER`/`GRANT`, and the droplet-destroy confirmation
   in the paired ADR-114 decommission work the same session) were executed by the founder
   directly, not the model** — Claude Code's own auto-mode safety classifier blocked both as
   sensitive infra actions requiring a human's own hand on the final step, consistent with this
   project's existing practice of the founder doing root/sudo-gated actions themselves ([[reference_forge_box_ssh]]'s sudo-password note). The model prepared the exact commands, the founder ran them, the model then verified and continued the rest (`.env` swap, `config:cache`, `horizon:terminate`, testing) itself once the underlying MySQL user existed.

**Rationale:**

- Never modifying or dropping `doadmin` means the entire change is reversible at every step short
  of the final `.env` swap, and that step itself reverts in seconds from a plain file backup — no
  scenario in this plan risks losing or corrupting existing data.
- Testing the new user against real tables/views *before* pointing the live app at it turns "will
  this work" into a verified fact rather than a theoretical grant list — the primary-key DDL
  hiccup is exactly the kind of surprise that audit was designed to catch before it could hit a
  real deploy.
- Reusing the `report_assistant` precedent (ADR-087) rather than inventing a new pattern keeps
  this project's credential-provisioning practice consistent and gives future sessions one place
  to look, not two different conventions.

**Consequence to track:**

- `.env.bak-2026-09-30-doadmin-least-privilege` is the rollback path if anything about the new
  user's grants turns out to be insufficient later (e.g. a future migration needs a privilege not
  in this grant list) — revert `.env`, `config:cache`, `horizon:terminate`, fix the grant, re-swap.
  `doadmin`'s own credentials are unchanged throughout, so this is always available.
- If a future migration needs a privilege outside this grant list (e.g. `CREATE ROUTINE` if this
  project ever adds a stored procedure), it will fail loudly on `migrate --force` during deploy —
  the fix is `GRANT <privilege> ON defaultdb.* TO 'pekangame_app'@'%'` via `doadmin`, not reverting
  to `doadmin` for the app connection.
- `docs/prd.md` §16 item 52 (which originally bundled this with the old-droplet decommission) is
  now fully closed on both halves.
