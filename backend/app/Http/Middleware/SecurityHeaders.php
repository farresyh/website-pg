<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRD §16 item 110: headers the Forge nginx defaults don't send
 * (it already adds X-Frame-Options and nosniff). Set here, not in nginx,
 * so they ship through staging → main like any other change.
 *
 * HSTS only over HTTPS (`secure()` is correct behind Cloudflare via
 * trustProxies), and without includeSubDomains/preload: those would bind
 * every pekangame.space host, which this API doesn't own.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
