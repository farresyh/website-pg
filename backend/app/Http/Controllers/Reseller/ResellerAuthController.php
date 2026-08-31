<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reseller\ResellerLoginRequest;
use App\Http\Requests\Reseller\ResellerSetPasswordRequest;
use App\Models\ResellerImpersonationSession;
use App\Models\ResellerUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-058 (58a): reseller-portal authentication, on the `reseller`
 * Sanctum guard. Mirrors App\Http\Controllers\Auth\AuthController's
 * shape (bearer token, generic credential error, deactivated-account
 * check, IP-logged failures — email only, never the attempted password)
 * against the separate reseller_users table.
 */
class ResellerAuthController extends Controller
{
    public function login(ResellerLoginRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $user = ResellerUser::query()->where('email', $email)->first();

        // A row whose invite is still unaccepted has a null password —
        // Hash::check() would throw on null, so short-circuit to the same
        // generic error an unknown email gets.
        if (! $user || $user->password === null || ! Hash::check($request->validated('password'), $user->password)) {
            Log::warning('Reseller login failed: invalid credentials', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            Log::warning('Reseller login failed: account deactivated', [
                'reseller_user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        Log::info('Reseller login succeeded', [
            'reseller_user_id' => $user->id,
            'reseller_id' => $user->reseller_id,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'token' => $user->createToken('reseller')->plainTextToken,
            'reseller_user' => $user->only(['id', 'reseller_id', 'name', 'email']),
            'reseller' => $user->reseller?->only(['id', 'business_name', 'status']),
        ]);
    }

    /**
     * Accept the emailed invite (ResellerInviteService) or a later reset:
     * the reseller sets their own password. Uses the `reseller_users`
     * broker so an admin never handles a plaintext password (ADR-058
     * decision 2).
     */
    public function setPassword(ResellerSetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('reseller_users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (ResellerUser $user, string $password) {
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
            'reseller_user' => $user->only(['id', 'reseller_id', 'name', 'email', 'last_login_at']),
            'reseller' => $user->reseller?->only(['id', 'business_name', 'status']),
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
        $session = ResellerImpersonationSession::query()
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
