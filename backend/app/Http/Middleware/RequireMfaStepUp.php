<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-121 decision 5: the five dangerous actions. An admin without active
 * MFA is refused outright; one with MFA needs a code accepted by
 * `POST /api/mfa/step-up` within the last 5 minutes of this session.
 *
 * `mfa.stepup:api_config` guards only requests carrying that field (a
 * supplier's credentials), so routine edits on the same route stay code-free.
 * StepUpRoutesTest pins which routes carry this middleware.
 */
class RequireMfaStepUp
{
    public const WINDOW_SECONDS = 300;

    public function handle(Request $request, Closure $next, ?string $onlyWithField = null): Response
    {
        if ($onlyWithField !== null && ! $request->has($onlyWithField)) {
            return $next($request);
        }

        $admin = $request->user();

        if (! $admin instanceof AdminUser || ! $admin->mfa_enabled) {
            return response()->json([
                'code' => 'mfa_not_enabled',
                'message' => 'This action needs MFA. Ask a super admin to require MFA on your account, then sign in again to set it up.',
            ], 403);
        }

        $steppedUpAt = $request->hasSession() ? (int) $request->session()->get('mfa_stepup_at', 0) : 0;

        if ($steppedUpAt < now()->timestamp - self::WINDOW_SECONDS) {
            return response()->json([
                'code' => 'mfa_step_up_required',
                'message' => 'Enter a code from your authenticator app to continue.',
            ], 403);
        }

        return $next($request);
    }
}
