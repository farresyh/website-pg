<?php

namespace App\Exceptions\ResellerApi;

use App\Models\ResellerApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * PRD §16 item 73 (ADR-074 2026-10-08 addendum decision 6): one log line
 * per Reseller API rejection, written from the one render hook in
 * `bootstrap/app.php`, so an integrator's "my order failed yesterday" can
 * be checked afterwards. Only a request whose key resolved is logged:
 * a missing or wrong key is anonymous internet noise that
 * `EnsureResellerApiKey` already rate-limits per IP. `warning`, not
 * `info`, because prod runs `LOG_LEVEL=warning`.
 */
final class ResellerApiRejectionLog
{
    public static function record(Request $request, ResellerApiException $rejection): void
    {
        $key = $request->attributes->get('reseller_api_key');

        if (! $key instanceof ResellerApiKey || ! $request->is('api/reseller/*')) {
            return;
        }

        // A client hammering past its limit gets one line a minute, not one per request.
        if ($rejection->errorCode === 'RATE_LIMITED'
            && ! Cache::add("reseller-api-rate-limited-logged:{$key->reseller_id}", true, 60)) {
            return;
        }

        Log::warning('Reseller API request rejected', [
            'reseller_id' => $key->reseller_id,
            'api_key_id' => $key->id,
            'error' => $rejection->errorCode,
            'status' => $rejection->statusCode,
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'details' => $rejection->details,
        ]);
    }
}
