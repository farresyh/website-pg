<?php

namespace App\Services\PlayerCheck;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare Turnstile server-side check for the public Game ID Checker
 * (ADR-123 decision 7.2). Fails closed: no secret, a network error or a
 * non-success answer all mean "not verified".
 */
class TurnstileVerifier
{
    public function isConfigured(): bool
    {
        return filled(config('services.turnstile.secret_key'));
    }

    public function verify(string $token, ?string $ip): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.turnstile.timeout'))
                ->post((string) config('services.turnstile.verify_url'), array_filter([
                    'secret' => config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (Throwable $e) {
            Log::warning('Turnstile verification request failed', ['error' => $e->getMessage()]);

            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
