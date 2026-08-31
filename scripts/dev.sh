#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

trap 'echo; echo "Stopping all dev servers..."; kill 0' EXIT INT TERM

run() {
  local name="$1" dir="$2"
  shift 2
  (cd "$ROOT_DIR/$dir" && "$@" 2>&1 | sed -u "s/^/[$name] /") &
}

# ADR-048: composer run dev's queue process (php artisan horizon) needs a
# real local Redis reachable — start it here so a plain ./scripts/dev.sh
# run doesn't need a separate manual `docker compose up -d redis` step
# first. Left running after Ctrl+C (not torn down by the trap above),
# same as how backend/docker-compose.yml's `mysql` service is already
# left running between sessions — ambient local infra, not an ephemeral
# script-managed process.
echo "Starting local Redis (backend/docker-compose.yml)..."
if ! (cd "$ROOT_DIR/backend" && docker compose up -d redis); then
  echo "Failed to start Redis — is Docker running? composer run dev's horizon process needs it (ADR-048)." >&2
  exit 1
fi
echo

# The reseller portal (ADR-059) needs the same two NEXT_PUBLIC_* vars as
# admin/ (the backend API base + the PrimeUI Community license key). It
# was added after admin/ and storefront/, so a machine set up before it
# has no reseller/.env.local — seed it from admin/'s once, so a plain
# ./scripts/dev.sh doesn't 404 the portal's API calls or flash the
# "Invalid PrimeUI License" banner. Never overwrites an existing file.
if [ ! -f "$ROOT_DIR/reseller/.env.local" ] && [ -f "$ROOT_DIR/admin/.env.local" ]; then
  echo "Seeding reseller/.env.local from admin/.env.local (first run since ADR-059)..."
  cp "$ROOT_DIR/admin/.env.local" "$ROOT_DIR/reseller/.env.local"
  echo
fi

# Each Next app needs its deps installed separately (no repo-root
# workspace). The reseller app is new — install on first run so
# ./scripts/dev.sh works without a manual `cd reseller && npm install`.
if [ ! -d "$ROOT_DIR/reseller/node_modules" ]; then
  echo "Installing reseller/ dependencies (first run)..."
  (cd "$ROOT_DIR/reseller" && npm install) || {
    echo "reseller/ npm install failed — run it manually." >&2
    exit 1
  }
  echo
fi

echo "Starting backend (composer run dev), admin, storefront, reseller (npm run dev)..."
echo "  admin      http://localhost:3000"
echo "  storefront http://localhost:3001"
echo "  reseller   http://localhost:3002"
echo "Press Ctrl+C to stop all."
echo

run backend    backend    composer run dev
run admin      admin      npm run dev
run storefront storefront npm run dev
run reseller   reseller   npm run dev

wait
