# docs-site/ — Reseller API developer docs (ADR-084 PR-4)

The **fourth** frontend, alongside `admin/`, `storefront/`, `reseller/`.
Astro + Starlight, deployed on Vercel to **`docs.pekangame.space`**. Pure
static output, no adapter. Read the repo-root `AGENTS.md` for project-wide
conventions.

Audience: an external reseller's own dev team. Benchmark set by the founder is
CHIP's docs (`docs.chip-in.asia`).

## What lives where

| Path | What |
| --- | --- |
| `src/content/docs/*.md` | The 10 hand-written guide pages + `index.mdx` landing |
| `astro.config.mjs` | Starlight config + the `starlight-openapi` plugin |
| `src/guide-sidebar.mjs` | The guide pages in reading order: the one list behind the sidebar and `/llms.txt`. Add a new page here |
| `src/pages/llms.txt.ts` | `/llms.txt`, built from that list and each page's title + description |
| `public/favicon.png` | PNG copy of PekanGame's favicon (Starlight takes no WebP); refresh if the brand favicon changes |

| `public/openapi.json` | **Committed, generated** — the OpenAPI spec. Also served raw at `/openapi.json` |
| `src/content/i18n/en.json` | Empty stub — silences Starlight's i18n-collection warning; do not delete |

**Public audience only.** These pages are read by outside developers. Never name
a supplier, the platform's margin or cost fields, an internal ADR reference, or
infrastructure. `node scripts/check-docs.mjs` (CI `docs-check`) fails on those
in the prose, `openapi.json` and `llms.txt`. The spec is built from PHP
docblocks, so this applies to backend comments too.

## The OpenAPI spec is generated, not hand-edited

`public/openapi.json` is produced from the backend's Scramble annotations:

```bash
cd ../backend && php artisan scramble:clear \
  && php artisan scramble:export --path=../docs-site/public/openapi.json --no-ansi --silent
```

**Never edit `public/openapi.json` by hand.** To change the API Reference,
change the `#[Endpoint]` / `#[Response]` PHP attributes on the
`app/Http/Controllers/ResellerApi/*` controllers and regenerate. CI's
`scramble-drift` job fails the build if the committed file and a fresh export
disagree.

`servers` and `info.version` come from `backend/config/scramble.php`
(`info.version` = the **docs** revision, not the API version — ADR-084
decision 9). Bump it there and note the change on the "Versioning & changelog"
page.

## Versions are pinned exact

No `^` in `package.json`. A solo-maintained app — an Astro or Starlight bump is
a deliberate PR (check the changelog, rebuild, eyeball the pages), never a
lockfile drift.

## Build & Test

```bash
npm run dev      # astro dev
npm run build    # static build -> dist/  (CI gate)
npm run check    # astro check (tsc)       (CI gate)
```

## Deploy

Vercel project `pekangame-docs`, root directory `docs-site/`, framework preset
Astro, auto-deploys from `main`. The domain + DNS are set up once — see
`scripts/adr-084-docs-site-wizard.sh`. `DOCS_SITE_URL=https://docs.pekangame.space`
is set on the backend (Forge), so `/docs/api` 301-redirects here.
