#!/usr/bin/env bash
# Vercel "Ignored Build Step" for admin/, storefront/, reseller/,
# docs-site/ (introduced PR #269, hardened PR after a real false-skip
# found on PR #270: 4 granular commits pushed together, the last one
# docs-only, made admin+storefront wrongly skip their real rebuild).
#
# Exit 0 = skip the build, exit 1 = build. Vercel runs this with cwd
# already at the project's own Root Directory, so a bare `.` below
# means "this app's own directory only".
#
# main/staging only ever receive a merge commit (GitHub's "Merge pull
# request" button) or a single hotfix commit, per this repo's Branch
# Workflow — `git diff HEAD^ HEAD` already gives the FULL diff of
# everything that landed (a merge commit's first-parent diff is the
# complete cumulative change, not just "the last commit").
#
# Any other ref is a feature/fix branch mid-PR, which can carry
# several individual commits pushed together in one `git push` — a
# bare HEAD^ HEAD only sees the LAST commit and can wrongly skip an
# app an EARLIER commit in the same push actually touched. Compare
# against the merge-base with staging instead, so the diff always
# covers the whole branch to date regardless of how many commits or
# pushes it took to get there.
#
# Fails open (exit 1, build) if the staging fetch or merge-base lookup
# doesn't resolve for any reason — a false build is wasted compute, a
# false skip is a shipped bug nobody sees.

set -u

if [ "${VERCEL_GIT_COMMIT_REF:-}" = "main" ] || [ "${VERCEL_GIT_COMMIT_REF:-}" = "staging" ]; then
  git diff --quiet HEAD^ HEAD .
  exit $?
fi

# Vercel's clone has no "origin" remote configured — fetch by URL.
repo_url="https://github.com/farresyh/website-pg.git"

if git fetch --depth=200 -q "$repo_url" staging 2>/dev/null; then
  base=$(git merge-base HEAD FETCH_HEAD 2>/dev/null || true)
  if [ -n "$base" ]; then
    echo "[vercel-ignore] diffing against staging merge-base $base"
    git diff --quiet "$base" HEAD .
    exit $?
  fi
fi

echo "[vercel-ignore] couldn't resolve a merge-base with staging — building rather than risking a false skip"
exit 1
