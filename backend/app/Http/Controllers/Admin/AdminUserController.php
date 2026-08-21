<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateAdminUserRequest;
use App\Http\Requests\Admin\UpdateAdminUserRequest;
use App\Http\Requests\Admin\UpdateAdminUserStatusRequest;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
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

    public function update(UpdateAdminUserRequest $request, AdminUser $admin_user): JsonResponse
    {
        $data = $request->validated();

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $admin_user->update($data);

        return response()->json($admin_user);
    }

    /**
     * A Super Admin can't deactivate their own account — otherwise a
     * single-admin setup (or a mistake) locks out the only account that
     * could undo it.
     */
    public function updateStatus(UpdateAdminUserStatusRequest $request, AdminUser $admin_user): JsonResponse
    {
        $isActive = $request->validated('is_active');

        if ($admin_user->is($request->user()) && ! $isActive) {
            throw ValidationException::withMessages([
                'is_active' => ['You cannot deactivate your own account.'],
            ]);
        }

        $admin_user->update(['is_active' => $isActive]);

        return response()->json($admin_user);
    }
}
