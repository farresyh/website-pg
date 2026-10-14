<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminUserRequest;
use App\Http\Requests\Admin\UpdateAdminUserRequest;
use App\Http\Requests\Admin\UpdateAdminUserStatusRequest;
use App\Models\AdminUser;
use App\Services\Auth\AdminMfa;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * AUTH-4: Super Admin manages admin users (create, edit, activate,
 * deactivate). Route-level admin.role:super_admin middleware is the
 * actual gate — this controller assumes it already ran.
 */
class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            AdminUser::query()->orderBy('created_at', 'desc')->get()
        );
    }

    public function store(CreateAdminUserRequest $request): JsonResponse
    {
        $admin = AdminUser::query()->create($request->validated());

        return response()->json($admin, 201);
    }

    public function update(UpdateAdminUserRequest $request, AdminUser $admin_user, AdminMfa $mfa): JsonResponse
    {
        $data = $request->validated();

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $admin_user->update($data);

        // ADR-121: a new password ends that admin's other sessions. Your own
        // stays: the guard gets the updated model, so Sanctum's
        // AuthenticateSession stores the new password hash after this
        // request instead of the old one (which logged you out next request).
        if (isset($data['password'])) {
            $isSelf = $admin_user->is($request->user());
            $mfa->endSessions($admin_user, $isSelf && $request->hasSession() ? $request->session()->getId() : null);

            if ($isSelf) {
                Auth::guard('web')->setUser($admin_user);
            }
        }

        return response()->json($admin_user);
    }

    /**
     * A Super Admin can't deactivate their own account — otherwise a
     * single-admin setup (or a mistake) locks out the only account that
     * could undo it.
     */
    public function updateStatus(UpdateAdminUserStatusRequest $request, AdminUser $admin_user, AdminMfa $mfa): JsonResponse
    {
        $isActive = $request->validated('is_active');

        if ($admin_user->is($request->user()) && ! $isActive) {
            throw ValidationException::withMessages([
                'is_active' => ['You cannot deactivate your own account.'],
            ]);
        }

        $admin_user->update(['is_active' => $isActive]);

        if (! $isActive) {
            $mfa->endSessions($admin_user);
        }

        return response()->json($admin_user);
    }

    /**
     * ADR-121 decision 3: one-way, and deliberately not behind step-up (it
     * only adds protection, and the first admin to use it has no code yet).
     * Turning MFA off is `php artisan admin:mfa-reset` only.
     */
    public function requireMfa(AdminUser $admin_user): JsonResponse
    {
        $admin_user->forceFill(['mfa_required' => true])->save();

        return response()->json($admin_user);
    }
}
