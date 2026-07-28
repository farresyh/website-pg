<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * AUTH-1/AUTH-2: Bearer token auth (Sanctum) with role-based
 * middleware on the backend. Frontend routing is convenience, never
 * security (foundation-security.md §1) — enforcement lives here and
 * in EnsureAdminRole, not in the Next.js admin panel.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $admin = AdminUser::query()->where('email', $email)->first();

        if (! $admin || ! Hash::check($request->validated('password'), $admin->password)) {
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

        Log::info('Admin login succeeded', [
            'admin_id' => $admin->id,
            'email' => $email,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'token' => $admin->createToken('api')->plainTextToken,
            'admin' => $admin,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }
}
