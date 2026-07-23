<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
        $admin = AdminUser::query()->where('email', $request->validated('email'))->first();

        if (! $admin || ! Hash::check($request->validated('password'), $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $admin->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

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
