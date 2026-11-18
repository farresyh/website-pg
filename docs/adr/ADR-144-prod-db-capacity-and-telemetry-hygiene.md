# ADR-144: Production database capacity and telemetry hygiene — 1 GiB kept on purpose with upgrade triggers, Pulse's cache recorder off by default, backups that restore every table

> **Standing (2026-11-18):** In force. Revises [ADR-020](./ADR-020-production-host.md) decision 2 (database size), [ADR-039](./ADR-039-database-backups.md) decisions 1 and 8 (what a backup carries, what the restore test demands) and [ADR-077](./ADR-077-storefront-read-path.md) decision 13 (Pulse recorders); records the size drift in [ADR-114](./ADR-114-production-infra-split-off-digitalocean-s-shared.md). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted, 2026-11-18. Grilled with the founder in Malay (`/mattpocock-skills:grilling`, three rounds, 8 questions), then stress-tested against the code and production (spatie's dump naming and option handling, every caller of the restore seam, mysqldump 8.4's options on the box, Redis stats, the readers and indexes of the audit tables, monthly order counts). The stress test changed two answers (Q3 → Q7, Q1 → Q8) and found four build-breaking details, listed under Consequence.

## Context

**Trigger.** From 2026-11-18 DigitalOcean emailed "Memory Utilization is running high" (critical, above 90% for 5 minutes) for the managed MySQL cluster `topup-prod-mysql`, several times a day (90.2% to 91.3%). The founder asked for the root cause and for a decision that is not a patch.

**Production, 2026-11-18 (read-only).**

| Fact | Value |
| --- | --- |
| Cluster size | **1 GiB RAM / 1 vCPU / 10 GiB disk** (DO console); `max_connections` 76, InnoDB buffer pool 32 MB, `table_open_cache` 200 |
| Memory, 14 days (DO Insights) | flat: about 83% on 5 Nov, about 86% from 11 Nov, spikes to about 91%. No climb, no restart (MySQL uptime 16.5 days) |
| CPU | 8–12% baseline, one 30–45% spike a day |
| Connections | 9–10 open, peak 20 of 76 |
| Data | 101 MB in all; `pulse_*` 72 MB of it (`pulse_entries` also 142 MB of free space from churn) |
| Writes a week | Pulse 217k entries (plus `pulse_aggregates` upserts); every business table together about 22k |
| Pulse entries by type, 7 days | `cache_hit` 105k + `cache_miss` 85k = 87%; `user_request` 17k; slow queries/requests/jobs and exceptions under 4% |
| Symptom | queries on tables of 4–5 rows (`affiliates`, `affiliate_domains`) at 0.3–2 s now and then; 2.4 million table-cache overflows |
| Orders | Sep 31, Oct 39, **Nov 1–18 613** |

ADR-020 decision 2 chose a **2 GiB** cluster. The cluster rebuilt for ADR-114 is **1 GiB**, and no document records why. On 1 GiB, MySQL 8.4's own fixed allocations already sit near 90%, so the alert fired on normal load. Memory stayed flat while November's orders grew fifteenfold: at this scale memory does not follow order volume.

**What the trace found beside the alert.**

1. **Pulse was the database's main workload.** The `CacheInteractions` recorder wrote one row per cache hit or miss. It filled most of the 32 MB buffer pool, pushing the business tables out.
2. **A real restore is missing two tables.** `exclude_tables` (ADR-039 decision 1, ADR-124 decision 3) becomes `mysqldump --ignore-table`, which drops a table's structure as well as its rows. So no backup contains `player_validations` or `order_credentials`. After a disaster restore the `migrations` table says they exist, `migrate` will not create them, and the Game ID check and Login Top-up orders fail until someone builds them by hand. The nightly restore test passed because it skipped the excluded tables. mysqldump 8.4 has no `--ignore-table-data` (checked on the box; it is a MariaDB option).
3. **Audit tables grow with no recorded retention**: `price_change_logs` (92k rows, +13.8k a week, about 65 MB a year), `deactivation_logs`, `package_reactivation_logs`, `price_sync_runs`, `order_resend_attempts`. Every reader filters on an indexed foreign key (`price_sync_run_id`, `package_id`).
4. **The database memory alert is recorded nowhere.** PRD §16 item 11 records only the droplet's.

## Decision

1. **Keep the 1 GiB cluster, on purpose.** Upgrade to 2 GiB, without another grill, when any of these happens:
   - (a) the DigitalOcean memory alert (decision 2) fires;
   - (b) the database restarts or runs out of memory (UptimeRobot on `/api/health` already reports the database down);
   - (c) Pulse still shows queries over 1 s on tables under 100 rows at the check two weeks after this ADR's release (PRD §16 item 195).

   Order volume is not a trigger: it does not move this database's memory at the current scale (see Context).
2. **The DigitalOcean memory alert is 95% for 5 minutes**, not 90%. At 95% it means close to out of memory, and by decision 1(a) it means upgrade. It is an account setting: the founder changes it in DigitalOcean.
3. **Pulse's `CacheInteractions` recorder is off by default** (`config/pulse.php`, so the default is reviewed code, not an env value). Its per-key hit rate is a diagnosis tool, which ADR-077 used to find the per-brand key problem. Redis `INFO stats` is not a replacement: it is server-wide and dominated by Horizon's polling. For a diagnosis, set `PULSE_CACHE_INTERACTIONS_ENABLED=true`, then remove it. The dashboard shows the Cache card only while the recorder is on. Every other recorder stays as it is, `UserRequests` included.
4. **Every backup restores every table.** On MySQL a second, structure-only dump of the excluded tables rides in the same archive: the `mysql_dump_schema` connection, which is the `mysql` connection with the same dump flags (`--set-gtid-purged=OFF`, `--single-transaction`) and only the table selection changed (`--tables <excluded> --no-data`). The excluded list is defined once (`config/database.php`). Dump files are named by connection, since the two dumps are of one database. `BackupRestorer` restores every dump in the archive under its single "the target must be empty" check, so the nightly test and `app:restore-backup` stay one path.
   - The restore test demands every live table, the excluded ones included, once new backups carry their structure.
   - Any excluded table that comes back must be empty, whatever the archive holds. This is the PII guarantee of ADR-021 and ADR-124 made into a check. It also catches a misspelt dump option, which spatie ignores silently.
   - An archive made before this ADR still restores. `app:restore-backup` names the excluded tables it lacks and says where to copy their structure from.
   - Not on sqlite (local and tests): spatie's sqlite dumper ignores `do_not_dump_data` and would copy rows.
5. **`pulse_*` stays in the backup.** With decision 3, Pulse holds about a tenth of its old size. The excluded list keeps one meaning, privacy, and size is not mixed into it.
6. **Audit tables are kept in full.** They explain why an order's cost and profit came out as they did, which the accountant may need. Revisit when any one passes 1 million rows or 500 MB.

## Rationale

- **The cause is fixed where it sits.** The alert was a size choice made silently. Decision 1 makes the choice deliberately and ties a change to signals that someone, or something, already watches. A trigger that nobody watches gets forgotten; this is why the stress test removed "monthly orders" and "average over 90% for 24 hours".
- **Telemetry should not be the main load on the system it measures.** Decision 3 removes 87% of Pulse's writes and keeps the tool one setting away.
- **One seam, one invariant.** The restore gap is closed in `BackupRestorer`, which both the nightly test and a real restore use, and is guarded by a check that holds for every archive (excluded tables empty) instead of a runbook step. A runbook step would be forgotten on the day it matters.
- **Smallest working change.** No new driver, no Pulse database, no prune jobs: the excluded list and the restore path are reused, and retention is a recorded decision with a threshold rather than code.

## Consequence to track

- **What the stress test changed before any code.** Spatie names a dump `{type}-{database}.sql`, so a second dump of the same database would have **overwritten the full backup** (hence connection-based names). `extractDump()` returned only `$dumps[0]` (hence the seam takes a list). A companion without the GTID and single-transaction flags would fail to restore or lock live tables (hence one shared flag set). Spatie skips an unknown dump option with no error (hence the empty-table invariant).
- **Memory will not drop much.** Decision 3 cuts writes and buffer-pool pressure, and should end the slow small-table queries; MySQL's fixed allocations are most of the 86%. Item 195's check measures this.
- **Verify after release:** Pulse's weekly entry count falls by about 87%; the next nightly archive holds two dumps (`mysql-mysql.sql`, `mysql-mysql_dump_schema.sql`) and its restore test passes with `excluded_tables_absent` empty.
- **An upgrade to 2 GiB** is a resize in DigitalOcean with a short failover. Pick a quiet hour.
- **A new privacy exclusion** goes into `$dumpExcludedTables` only; the companion dump picks it up.
