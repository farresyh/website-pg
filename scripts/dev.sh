#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

trap 'echo; echo "Stopping all dev servers..."; kill 0' EXIT INT TERM

run() {
  local name="$1" dir="$2"
  shift 2
  (cd "$ROOT_DIR/$dir" && "$@" 2>&1 | sed -u "s/^/[$name] /") &
}

echo "Starting backend (composer run dev), admin (npm run dev), storefront (npm run dev)..."
echo "Press Ctrl+C to stop all."
echo

run backend    backend    composer run dev
run admin      admin      npm run dev
run storefront storefront npm run dev

wait
