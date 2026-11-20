# ADR-145: Server patching — planned patch windows (full upgrade, then reboot), a daily alert when PHP falls behind or a reboot waits, and a smaller attack surface

> **Standing (2026-11-21):** In force; 2026-11-21 addendum (first window run, runbook made non-interactive, PHP source moves to packages.sury.org). Extends [ADR-114](./ADR-114-production-infra-split-off-digitalocean-s-shared.md) (the box) and reuses the admin email path of [ADR-039](./ADR-039-database-backups.md) decision 7. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted, 2026-11-20. The founder handed Claude the decision ("saya serahkan keputusan kepada awak"), asking for a fix that is maintainable, reliable and not a patch. It rests on a read-only audit of the box (packages, apt sources, unattended-upgrades, listeners, firewall from outside, nginx, PHP-FPM settings) and of php.net and the PPA.

## Context

**Trigger.** On 2026-11-19 PHP shipped security releases for every supported branch (8.4.28 for us), with seven critical issues. We learned of it from another company's internal notice, forwarded by the founder. Nothing of ours had told us.

**The box, 2026-11-20 (read-only).**

| Fact | Finding |
| --- | --- |
| PHP | 8.4.27 (CLI and FPM) from Ondrej's PPA, through the `ppa.setup-php.com` mirror. Neither the mirror nor Launchpad had 8.4.28 yet on 2026-11-20. |
| unattended-upgrades | On, but `Allowed-Origins` is `${distro_id}:${distro_codename}-security` only. PHP, nginx (nginx.org), Redis (redis.io), Node, the MySQL client and pgdg are never upgraded by it. |
| Reboot | `/var/run/reboot-required` present. The running kernel is 6.8.0-124; kernels 142 to 149 and `libc6` are installed and wait for a reboot. Up 8 weeks. |
| Pending upgrades | 40 packages, including nginx 1.31.6→1.31.9, Redis 8.8.1→8.10.3, glib, apparmor, sqlite and the MySQL client. |
| CVE exposure today | FPM request smuggling (CVE-2026-129289) needs nginx to reuse FastCGI connections: `fastcgi_keep_conn` is not set, so not exposed. Session upload progress (CVE-2026-115560) needs a non-default `session.serialize_handler` switched at runtime: ours is `php`, and Laravel doesn't use PHP's native sessions, so not exposed. The timezone cache use-after-free (CVE-2026-129496) has no published preconditions yet. |
| Edge | The origin accepts only Cloudflare (Authenticated Origin Pulls: a direct request gets 400 "No required SSL certificate was sent"). |
| Listeners | Redis on `0.0.0.0:6379` and Memcached on `0.0.0.0:11211`, closed from outside by the firewall. Every Redis client is `127.0.0.1` (68 connections); Memcached has none, and the app caches in Redis (ADR-077). |
| PHP extensions | FPM loads `imap` (unmaintained), `swoole`, `pgsql`, `soap`, `memcached` and `imagick`. None is in the app's platform requirements (`composer check-platform-reqs --lock --no-dev`); images use GD. |

**The root cause** is not the one missing PHP release. Three repositories the app runs on are patched by hand, a reboot is never triggered, and no signal says either is overdue.

## Decision

1. **Ubuntu's security pocket stays automatic; third-party repositories are patched in planned windows, never automatically.** Adding Ondrej's PPA to unattended-upgrades would restart PHP-FPM, and with Redis, the queue, at an unplanned hour on a money system, while Horizon workers keep the old PHP until restarted. A planned window does all of it at once and is checked afterwards.
2. **A patch window is a full upgrade followed by a reboot, at the quietest hour, then health checks.** Not a pick of single packages: one window brings PHP, nginx, Redis, glib and the kernel to current together, and a reboot is the only clean way to put every process (FPM, Horizon, Reverb, Redis, OpenWA) on the new binaries. The quietest hours over the last 30 days are 03:00 and 06:00 MYT (4 paid orders each in the month). The founder runs it, since Claude has no `sudo` on the box, and Claude verifies it and records it in `docs/build-log.md`.
3. **A daily alert closes the "no signal" gap.** `app:check-server-patches` runs at 09:00 MYT (task `server-patch-check`, routine tier). It compares the running PHP with php.net's latest release of the same branch (`https://www.php.net/releases/?json=&version=8.4`), and checks `/var/run/reboot-required`, listing `reboot-required.pkgs` without repeats. Each problem is emailed to every admin through `BackupFailureAlerter` (area "Server"): once when it appears or changes (a newer PHP), then every 7 days while it stays. A resolved problem drops out of the state, so its return is emailed at once. If php.net is unreachable or gives no version, the check logs a warning and sends nothing. The state is one cache key; losing it costs one repeated email.
4. **The first window also shrinks the attack surface.** Redis binds to `127.0.0.1 -::1` only. Memcached is purged. The unused extensions `imap`, `swoole`, `pgsql`, `soap`, `memcached` and `imagick` are purged. The MySQL client stays, since backups use `mysqldump`. The inactive local `mysql-server` is left alone: it is Forge's, it is stopped, and removing it gains nothing.
5. **Interim for this release (2026-11-19):** no maintenance mode and no Cloudflare "Under Attack". The two critical issues with published preconditions don't apply to us, the origin is Cloudflare-only, and stopping sales for an unknown is worse than patching as soon as the PPA has 8.4.28.

### The patch window (runbook)

Run over SSH as `forge` at 03:00 or 06:00 MYT.

```bash
# 0. The patch must be in the PPA, or stop here.
apt-cache policy php8.4-fpm | head -3                  # Candidate: 8.4.28 or later

# 1. First window only: decision 4.
sudo grep -nE '^(bind|protected-mode)' /etc/redis/redis.conf
sudo sed -i 's/^bind .*/bind 127.0.0.1 -::1/' /etc/redis/redis.conf   # if a bind line exists
sudo grep -q '^bind ' /etc/redis/redis.conf || echo 'bind 127.0.0.1 -::1' | sudo tee -a /etc/redis/redis.conf
sudo apt purge -y memcached php8.4-memcached php8.4-imap php8.4-swoole php8.4-pgsql php8.4-soap php8.4-imagick

# 2. Every window.
sudo apt update && sudo apt full-upgrade -y
sudo apt autoremove -y
sudo reboot
```

Then Claude checks, read-only:

- `php -v` and FPM's version
- `/var/run/reboot-required` is gone
- `ss -ltn` shows Redis on `127.0.0.1` and nothing on 11211
- `/api/health` returns 200
- Horizon is running
- the scheduler heartbeat is fresh
- a new paid order delivers
- the next 09:00 alert run sends nothing

## Rationale

- **Fix where the gap is.** The release was missed because no signal existed and because patching a few packages by hand is easy to forget. One daily check plus one routine replaces both, with no new infrastructure: the email path, the scheduler and the heartbeat tiers already exist.
- **Planned beats automatic for the runtime.** Ubuntu's security pocket rarely restarts the app. A PHP, Redis or nginx upgrade always does.
- **php.net is the source of truth for "behind".** The PPA lags by days, and the email says to check it before acting.

## Consequence to track

- The alert compares the CLI's PHP. FPM runs the same packages and the window ends in a reboot, so they can't diverge unless someone upgrades PHP without one, which the runbook forbids.
- A PHP branch reaching end of life is not alerted (php.net's JSON for a dead branch keeps its last version). PHP 8.4's security support ends on 2028-12-31: revisit then, or at the first move to 8.5.
- If Forge's provisioning ever re-adds Memcached or rebinds Redis, the next window's `ss -ltn` check shows it.
- Each window, and its before and after versions, is recorded in `docs/build-log.md`; the first one is PRD §16 item 198.

### 2026-11-21 addendum — first window run; runbook made non-interactive; Ondrej's PPA is moving to packages.sury.org

**The first window ran on 2026-11-21, 05:00–05:11 MYT** (founder at the keyboard, Claude verifying read-only). Before and after are in `docs/build-log.md` 2026-11-21. Three things it taught change the runbook:

1. **The upgrade runs non-interactively and keeps local config files.** Step 1 edits `/etc/redis/redis.conf`, and Forge owns the nginx config. A plain `apt full-upgrade -y` still stops at dpkg's "keep your version?" question for a changed config file, halfway through a window. The upgrade line is now `sudo DEBIAN_FRONTEND=noninteractive apt-get -o Dpkg::Options::=--force-confold -o Dpkg::Options::=--force-confdef full-upgrade -y`. On 2026-11-21 dpkg logged "Keeping old config file as default" for `redis.conf`, and the localhost bind survived the Redis 8.10.3 upgrade.
2. **Redis is restarted and checked right after the bind change, before the upgrade.** `sudo systemctl restart redis-server`, then `ss -ltn | grep 6379` (only `127.0.0.1` and `[::1]`) and `redis-cli ping` (`PONG`). A bad bind then shows while the window is still open, not after the reboot. The `grep -q '^bind ' || tee -a` fallback only applies when no `bind` line exists. The box has one, at line 93.
3. **Ondrej's PPA now carries the label "PPA for PHP (deprecated)", on Launchpad and on the `ppa.setup-php.com` mirror.** `apt update` stops until the change is accepted with `y`, which the founder did on 2026-11-21. The maintainer is moving the packages to `packages.sury.org/php`. Ubuntu 26.04 is served only from there, and 22.04 and 24.04 are still served from both for now, by third-party reports ([ma.ttias.be](https://ma.ttias.be/php-security-release-2026-11-19/), [Nextcloud forum](https://help.nextcloud.com/t/ubuntu-apt-error-repository-ondrej-php-changed-its-label-value-the-ppa-is-moving-to-packages-sury-org/246810)). **Decision:** the box's PHP source moves from `/etc/apt/sources.list.d/ondrej-php.list` (the mirror) to `packages.sury.org/php` with its keyring, run by the founder (PRD §16 item 199). Until then, the PPA still ships 8.4 for noble, and the daily alert still compares against php.net, so a stalled PPA shows as "behind" and is not missed.

The runbook in **Decision** above stays the reference, with these three changes applied.
