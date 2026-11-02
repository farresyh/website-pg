#!/usr/bin/env bash
# OpenWA upgrade on the prod box (ADR-114 2026-11-02 addendum).
#
# Layout: ~/openwa-deploy/releases/<tag> (code), ~/openwa-deploy/shared/{data,.env}
# (state, symlinked into each release), ~/OpenWA -> the live release.
#
# Run from your machine; the script is piped to the box, nothing is copied there:
#   ssh pekangame-prod-lwf 'bash -s' -- build  v0.27.2 < scripts/openwa-upgrade.sh
#   (founder stops the OpenWA daemon in Forge)
#   ssh pekangame-prod-lwf 'bash -s' -- switch v0.27.2 < scripts/openwa-upgrade.sh
#   (founder starts the daemon)
# Rollback = `switch <previous tag>` the same way. If the new release migrated
# OpenWA's database, also restore the tarball `switch` printed.
#
# `build` never touches the running gateway. `switch` refuses while OpenWA answers.
set -euo pipefail

DEPLOY="$HOME/openwa-deploy"
LIVE="$HOME/OpenWA"
BACKUPS="$HOME/openwa-backups"
REPO="https://github.com/rmyndharis/OpenWA.git"

cmd="${1:-}"
tag="${2:-}"
[[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "usage: build|switch vX.Y.Z" >&2; exit 2; }
rel="$DEPLOY/releases/$tag"

case "$cmd" in
  build)
    [ ! -e "$rel" ] || { echo "$rel already exists" >&2; exit 1; }
    git clone -q -c advice.detachedHead=false --depth 1 --branch "$tag" "$REPO" "$rel"
    cd "$rel"
    log="$DEPLOY/releases/$tag.build.log"
    echo "building $tag (log: $log)..."
    { nice -n 10 npm ci && (cd dashboard && nice -n 10 npm ci) && nice -n 10 npm run build:all; } >"$log" 2>&1 \
      || { echo "build failed, see $log" >&2; exit 1; }
    [ -f dist/main.js ] && [ -f dashboard/dist/index.html ] || { echo "build left no dist/main.js or dashboard/dist" >&2; exit 1; }
    ln -s "$DEPLOY/shared/data" data
    ln -s "$DEPLOY/shared/.env" .env
    echo "built $tag: $(node -p 'require("./package.json").version'). Stop the daemon, then run switch $tag."
    ;;
  switch)
    [ -f "$rel/dist/main.js" ] && [ -L "$rel/data" ] && [ -L "$rel/.env" ] || { echo "$rel is not a built release" >&2; exit 1; }
    [ -L "$LIVE" ] || { echo "$LIVE is not a symlink; refusing" >&2; exit 1; }
    if curl -s -m 3 -o /dev/null http://127.0.0.1:2785/api/health; then
      echo "OpenWA is still running; stop the daemon in Forge first" >&2; exit 1
    fi
    from="$(basename "$(readlink -f "$LIVE")")"
    mkdir -p "$BACKUPS"
    backup="$BACKUPS/openwa-$from-$(date -u +%Y%m%d-%H%M).tar.gz"
    tar -czf "$backup" -C "$DEPLOY/shared" data .env
    ln -sfn "$rel" "$LIVE"
    echo "backup: $backup ($(du -h "$backup" | cut -f1))"
    echo "live:   $from -> $(readlink -f "$LIVE") ($(cd "$LIVE" && node -p 'require("./package.json").version'))"
    echo "Start the daemon in Forge."
    ;;
  *)
    echo "usage: build|switch vX.Y.Z" >&2; exit 2
    ;;
esac
