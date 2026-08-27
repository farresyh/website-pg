<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ADR-048 addendum: Horizon/Pulse ship their own session-cookie-gated
 * dashboards (`web` guard), but this backend is otherwise Sanctum
 * bearer-token-only (AuthController::login() never calls Auth::login()) —
 * a plain browser tab has no bearer token to send. `mint()` (Sanctum-gated,
 * `admin.role:super_admin`, same as every other /middleware/* route) issues
 * a short-lived signed URL; visiting it (`enter()`, `signed` + `web`
 * middleware) is the one and only place this backend ever calls
 * `Auth::guard('web')->login()`, bootstrapping the session Horizon/Pulse's
 * own dashboards then read from on every subsequent same-origin request.
 * No standing session-based login form exists anywhere else.
 */
class OpsAccessController extends Controller
{
    private const TARGETS = [
        'horizon' => 'horizon.index',
        'pulse' => 'pulse',
    ];

    public function mint(Request $request, string $target): JsonResponse
    {
        if (! array_key_exists($target, self::TARGETS)) {
            throw new NotFoundHttpException;
        }

        $url = URL::temporarySignedRoute(
            'ops.enter',
            now()->addMinutes(5),
            ['admin_id' => $request->user()->id, 'target' => $target],
        );

        return response()->json(['url' => $url]);
    }

    public function enter(Request $request): RedirectResponse
    {
        $target = $request->query('target');
        $routeName = self::TARGETS[$target] ?? null;

        if ($routeName === null) {
            throw new NotFoundHttpException;
        }

        $admin = AdminUser::query()->find($request->query('admin_id'));

        // Re-checked here, not just at mint time — the signature only
        // proves the link wasn't tampered with, not that the admin is
        // still active/super_admin at the moment it's actually used.
        if ($admin === null || ! $admin->is_active || $admin->role !== 'super_admin') {
            abort(403);
        }

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        return redirect()->route($routeName);
    }
}
