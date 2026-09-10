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
| `astro.config.mjs` | Starlight config + sidebar + the `starlight-openapi` plugin |
| `public/openapi.json` | **Committed, generated** — the OpenAPI spec. Also served raw at `/openapi.json` |
| `src/content/i18n/en.json` | Empty stub — silences Starlight's i18n-collection warning; do not delete |

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
`scripts/adr-084-docs-site-wizard.sh`. Once the domain is verified, set
`DOCS_SITE_URL=https://docs.pekangame.space` on the backend (Forge) so
`/docs/api` 301-redirects here.
