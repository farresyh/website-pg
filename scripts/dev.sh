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

echo "Starting backend (composer run dev), admin, storefront, reseller (npm run dev)..."
echo "Press Ctrl+C to stop all."
echo

run backend    backend    composer run dev
run admin      admin      npm run dev
run storefront storefront npm run dev
run reseller   reseller   npm run dev

wait
