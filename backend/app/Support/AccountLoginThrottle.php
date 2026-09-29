<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Wave 3 PR-B (ADR-019 addendum 2026-09-29): per-account failed-login
 * lockout, on top of each login route's per-IP `throttle:5,1,*`. The IP
 * bucket can't see a guess spread across many IPs; this one counts
 * failures against the email itself.
 *
 * Deliberately short (15 minutes, not permanent): anyone who knows an
 * admin's email can trip it, so the worst they can do is make that admin
 * wait 15 minutes — never lock them out for good.
 */
final class AccountLoginThrottle
{
    private const MAX_FAILURES = 10;

    private const DECAY_SECONDS = 15 * 60;

    /** @param  string  $guard  'admin' / 'affiliate' — separate user tables, separate buckets */
    public function __construct(private readonly string $guard, private readonly string $email) {}

    /** Throws 429 while locked — checked BEFORE the password, so a correct one is refused too. */
    public function ensureNotLocked(): void
    {
        if (RateLimiter::tooManyAttempts($this->key(), self::MAX_FAILURES)) {
            $minutes = (int) ceil(RateLimiter::availableIn($this->key()) / 60);

            throw ValidationException::withMessages([
                'email' => ["Too many failed login attempts. Try again in {$minutes} minute(s)."],
            ])->status(429);
        }
    }

    public function recordFailure(): void
    {
        RateLimiter::hit($this->key(), self::DECAY_SECONDS);
    }

    public function clear(): void
    {
        RateLimiter::clear($this->key());
    }

    private function key(): string
    {
        return "login-account:{$this->guard}:".mb_strtolower($this->email);
    }
}
