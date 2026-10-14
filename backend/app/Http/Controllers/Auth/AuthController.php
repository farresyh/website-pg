<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\MfaCodeRequest;
use App\Models\AdminUser;
use App\Services\Auth\AdminMfa;
use App\Support\AccountLoginThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * AUTH-1/AUTH-2 + ADR-121: the admin login is a cookie session (Sanctum SPA
 * mode), never a bearer token. A password alone completes the login only
 * for an account that does not require MFA; otherwise the session holds a
 * pending admin id until `/login/mfa` accepts a code. Frontend routing is
 * convenience, never security (foundation-security.md §1).
 */
class AuthController extends Controller
{
    /** How long a password-verified login waits for its code. */
    private const PENDING_SECONDS = 300;

    public function __construct(private readonly AdminMfa $mfa) {}

    /** The admin app sends this back as `X-CSRF-TOKEN`; the session cookie is host-only, so it can't read an XSRF cookie. */
    public function csrfToken(Request $request): JsonResponse
    {
        $this->ensureSession($request);

        return response()->json(['token' => $request->session()->token()]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $this->ensureSession($request);

        $email = $request->validated('email');
        $throttle = new AccountLoginThrottle('admin', $email);
        $throttle->ensureNotLocked();

        $admin = AdminUser::query()->where('email', $email)->first();

        if (! $admin || ! Hash::check($request->validated('password'), $admin->password)) {
            $throttle->recordFailure();
            // ADR-019: no audit trail existed for a brute-force attempt
            // or a compromised account before this line — email only,
            // never the attempted password.
            Log::warning('Admin login failed: invalid credentials', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $admin->is_active) {
            Log::warning('Admin login failed: account deactivated', [
                'admin_id' => $admin->id,
                'email' => $email,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        if (! $admin->mfa_required && ! $admin->mfa_enabled) {
            $throttle->clear();

            return $this->completeLogin($request, $admin);
        }

        $request->session()->put('mfa_pending', ['admin_id' => $admin->id, 'expires_at' => now()->addSeconds(self::PENDING_SECONDS)->timestamp]);

        return $admin->mfa_enabled
            ? response()->json(['mfa' => 'challenge'])
            : response()->json(['mfa' => 'setup', 'setup' => $this->mfa->setupData($admin)]);
    }

    /** Second step: a setup confirmation or a login code (TOTP or recovery). */
    public function loginMfa(MfaCodeRequest $request): JsonResponse
    {
        $this->ensureSession($request);

        $pending = $request->session()->get('mfa_pending');
        $admin = ($pending && $pending['expires_at'] >= now()->timestamp)
            ? AdminUser::query()->find($pending['admin_id'])
            : null;

        if (! $admin || ! $admin->is_active) {
            $request->session()->forget('mfa_pending');

            throw ValidationException::withMessages(['code' => ['This login has expired. Sign in again.']]);
        }

        $throttle = new AccountLoginThrottle('admin', $admin->email);
        $throttle->ensureNotLocked();

        $code = $request->validated('code');
        $recoveryCodes = null;

        $accepted = $admin->mfa_enabled
            ? $this->mfa->verify($admin, $code)
            : ($recoveryCodes = $this->mfa->confirmSetup($admin, $code)) !== null;

        if (! $accepted) {
            $this->mfa->recordFailedCode($admin, $throttle, $request->ip());

            throw ValidationException::withMessages(['code' => ['The code is incorrect.']]);
        }

        $throttle->clear();
        $request->session()->forget('mfa_pending');

        return $this->completeLogin($request, $admin->refresh(), $recoveryCodes);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    /** @param  list<string>|null  $recoveryCodes  only right after setup, shown once */
    private function completeLogin(Request $request, AdminUser $admin, ?array $recoveryCodes = null): JsonResponse
    {
        Auth::guard('web')->login($admin);
        $request->session()->regenerate();
        $request->session()->put('authenticated_at', now()->timestamp);

        Log::info('Admin login succeeded', [
            'admin_id' => $admin->id,
            'email' => $admin->email,
            'ip' => $request->ip(),
            'mfa' => $admin->mfa_enabled,
        ]);

        return response()->json(array_filter([
            'admin' => $admin,
            'recovery_codes' => $recoveryCodes,
        ], fn ($v) => $v !== null));
    }

    /** Only the admin app (a Sanctum stateful origin) gets a session; anything else can't log in. */
    private function ensureSession(Request $request): void
    {
        if (! $request->hasSession()) {
            abort(response()->json(['message' => 'Admin login is only available from the admin app.'], 400));
        }
    }
}
