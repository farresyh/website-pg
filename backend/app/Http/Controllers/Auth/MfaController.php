<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireMfaStepUp;
use App\Http\Requests\Auth\MfaCodeRequest;
use App\Services\Auth\AdminMfa;
use App\Support\AccountLoginThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** ADR-121: step-up for the dangerous actions, and the admin's own recovery codes. */
class MfaController extends Controller
{
    public function __construct(private readonly AdminMfa $mfa) {}

    public function stepUp(MfaCodeRequest $request): JsonResponse
    {
        $admin = $request->user();

        if (! $request->hasSession() || ! $admin->mfa_enabled) {
            throw ValidationException::withMessages(['code' => ['MFA is not active on this account.']]);
        }

        $throttle = new AccountLoginThrottle('admin', $admin->email);
        $throttle->ensureNotLocked();

        if (! $this->mfa->verify($admin, $request->validated('code'))) {
            $this->mfa->recordFailedCode($admin, $throttle, $request->ip());

            throw ValidationException::withMessages(['code' => ['The code is incorrect.']]);
        }

        $throttle->clear();
        $request->session()->put('mfa_stepup_at', now()->timestamp);

        return response()->json(['valid_for_seconds' => RequireMfaStepUp::WINDOW_SECONDS]);
    }

    /** Behind `mfa.stepup`. Every older code stops working. */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        return response()->json(['recovery_codes' => $this->mfa->regenerateRecoveryCodes($request->user())]);
    }
}
