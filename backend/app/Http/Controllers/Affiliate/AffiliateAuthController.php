<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Affiliate\AffiliateLoginRequest;
use App\Http\Requests\Affiliate\AffiliateSetPasswordRequest;
use App\Models\AffiliateImpersonationSession;
use App\Models\AffiliateUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-058 (58a): affiliate-portal authentication, on the `affiliate`
 * Sanctum guard. Mirrors App\Http\Controllers\Auth\AuthController's
 * shape (bearer token, generic credential error, deactivated-account
 * check, IP-logged failures — email only, never the attempted password)
 * against the separate affiliate_users table.
 */
class AffiliateAuthController extends Controller
{
    public function login(AffiliateLoginRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $user = AffiliateUser::query()->where('email', $email)->first();

        // A row whose invite is still unaccepted has a null password —
        // Hash::check() would throw on null, so short-circuit to the same
        // generic error an unknown email gets.
        if (! $user || $user->password === null || ! Hash::check($request->validated('password'), $user->password)) {
            Log::warning('Affiliate login failed: invalid credentials', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            Log::warning('Affiliate login failed: account deactivated', [
                'affiliate_user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        Log::info('Affiliate login succeeded', [
            'affiliate_user_id' => $user->id,
            'affiliate_id' => $user->affiliate_id,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'token' => $user->createToken('affiliate')->plainTextToken,
            'affiliate_user' => $user->only(['id', 'affiliate_id', 'name', 'email']),
            'affiliate' => $user->affiliate?->only(['id', 'business_name', 'status']),
        ]);
    }

    /**
     * Accept the emailed invite (AffiliateInviteService) or a later reset:
     * the affiliate sets their own password. Uses the `affiliate_users`
     * broker so an admin never handles a plaintext password (ADR-058
     * decision 2).
     */
    public function setPassword(AffiliateSetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('affiliate_users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (AffiliateUser $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json(['message' => 'Password set. You can now log in.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'affiliate_user' => $user->only(['id', 'affiliate_id', 'name', 'email', 'last_login_at']),
            'affiliate' => $user->affiliate?->only(['id', 'business_name', 'status']),
            // ADR-058 RES-4 / ADR-059 59c: non-null only when this token
            // was minted for an admin impersonation session (ability
            // `impersonate`) and that session is still open. Drives the
            // portal's persistent "Impersonating … — acting as …" banner.
            'impersonation' => $this->impersonationContext($request),
        ]);
    }

    /**
     * @return array{session_id: int, admin_name: string|null, started_at: string|null}|null
     */
    private function impersonationContext(Request $request): ?array
    {
        $token = $request->user()->currentAccessToken();

        if ($token === null) {
            return null;
        }

        // Keyed on the session row, not the token's ability list — see
        // ImpersonationController::end() for why.
        $session = AffiliateImpersonationSession::query()
            ->with('admin:id,name')
            ->where('personal_access_token_id', $token->getKey())
            ->whereNull('ended_at')
            ->first();

        if ($session === null) {
            return null;
        }

        return [
            'session_id' => $session->id,
            'admin_name' => $session->admin?->name,
            'started_at' => $session->started_at?->toIso8601String(),
        ];
    }
}
