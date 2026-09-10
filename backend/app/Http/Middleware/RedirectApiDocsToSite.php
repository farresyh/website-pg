<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-084 PR-4 decision 7: once the Starlight docs site is live at
 * `docs.pekangame.space`, the backend's built-in Scramble routes
 * (`/docs/api` UI, `/docs/api.json` spec) 301-redirect there — the docs
 * site is the single canonical home, and `openapi.json` is served
 * statically from it.
 *
 * Gated on `services.docs_site.url` (env `DOCS_SITE_URL`), NOT on
 * `app()->isProduction()`: an empty value keeps the Scramble UI reachable
 * everywhere (local dev, and prod until the site's DNS is verified). The
 * founder sets `DOCS_SITE_URL` on Forge the moment the Vercel domain is
 * live — no redeploy of this code needed to flip it.
 *
 * Prepended to `config/scramble.php`'s `middleware` list so it runs
 * before `RestrictedDocsAccess` — a redirect shouldn't depend on the
 * docs gate.
 */
class RedirectApiDocsToSite
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = config('services.docs_site.url');

        if (! is_string($site) || $site === '') {
            return $next($request);
        }

        $site = rtrim($site, '/');
        $target = str_ends_with($request->path(), '.json') ? $site.'/openapi.json' : $site;

        return redirect()->away($target, 301);
    }
}
