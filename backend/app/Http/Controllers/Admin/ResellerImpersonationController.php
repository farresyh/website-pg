<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImpersonateResellerRequest;
use App\Models\Reseller;
use App\Models\ResellerImpersonationSession;
use App\Models\ResellerUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ADR-058 58b (RES-4): a super_admin opens an impersonation session for
 * a reseller portal (ADR-059). We mint a short-lived `reseller`-guard
 * Sanctum token, scoped with an `impersonate` ability, against one of
 * the reseller's active portal users. The session runs under the real
 * `reseller` guard, so ADR-057's tenant scope applies unchanged — an
 * impersonating admin sees exactly what the reseller sees.
 *
 * Every session is audited: `reseller_impersonation_sessions` gets a row
 * on start (real admin identity, portal identity, token id, IP) and it
 * is closed on end / token revocation / reseller deactivation. The
 * persistent "Impersonating {reseller} — acting as {admin}" banner and
 * per-request tagging are the portal's job (ADR-059), which reads the
 * token's `impersonate` ability + this table.
 */
class ResellerImpersonationController extends Controller
{
    /** Minted tokens live this long — long enough for a debugging session, short enough to not linger. */
    private const TOKEN_TTL_MINUTES = 60;

    public function index(): JsonResponse
    {
        $sessions = ResellerImpersonationSession::query()
            ->with(['reseller:id,business_name', 'admin:id,name', 'resellerUser:id,name,email'])
            ->orderByDesc('started_at')
            ->limit(100)
            ->get()
            ->map(fn (ResellerImpersonationSession $s) => [
                'id' => $s->id,
                'reseller' => $s->reseller?->business_name,
                'reseller_id' => $s->reseller_id,
                'admin' => $s->admin?->name,
                'acting_as' => $s->resellerUser?->email,
                'reason' => $s->reason,
                'ip' => $s->ip,
                'started_at' => $s->started_at,
                'ended_at' => $s->ended_at,
                'ended_reason' => $s->ended_reason,
                'active' => $s->isActive(),
            ]);

        return response()->json(['sessions' => $sessions]);
    }

    public function store(ImpersonateResellerRequest $request, Reseller $reseller): JsonResponse
    {
        if ($reseller->status !== 'active') {
            throw ValidationException::withMessages([
                'reseller' => ['This reseller is not active — reactivate it before impersonating.'],
            ]);
        }

        $target = $reseller->users()
            ->where('is_active', true)
            ->orderByRaw('password is null')  // prefer a user who has accepted their invite
            ->orderBy('id')
            ->first();

        if (! $target instanceof ResellerUser) {
            throw ValidationException::withMessages([
                'reseller' => ['This reseller has no active portal user to impersonate. Add one first.'],
            ]);
        }

        $expiresAt = now()->addMinutes(self::TOKEN_TTL_MINUTES);
        $newToken = $target->createToken(
            "impersonation:admin#{$request->user()->id}",
            ['impersonate'],
            $expiresAt,
        );

        $session = ResellerImpersonationSession::query()->create([
            'reseller_id' => $reseller->id,
            'admin_user_id' => $request->user()->id,
            'reseller_user_id' => $target->id,
            'personal_access_token_id' => $newToken->accessToken->id,
            'reason' => $request->validated('reason'),
            'ip' => $request->ip(),
            'started_at' => now(),
        ]);

        Log::info('Reseller impersonation started', [
            'session_id' => $session->id,
            'reseller_id' => $reseller->id,
            'admin_user_id' => $request->user()->id,
            'reseller_user_id' => $target->id,
        ]);

        return response()->json([
            'session_id' => $session->id,
            'token' => $newToken->plainTextToken,
            'acting_as' => $target->only(['id', 'name', 'email']),
            'portal_url' => rtrim((string) config('services.reseller_portal.url'), '/'),
            'expires_at' => $expiresAt,
        ], 201);
    }

    public function end(ResellerImpersonationSession $impersonation_session): JsonResponse
    {
        if ($impersonation_session->ended_at !== null) {
            return response()->json(['message' => 'Session already ended.']);
        }

        if ($impersonation_session->personal_access_token_id !== null) {
            DB::table('personal_access_tokens')
                ->where('id', $impersonation_session->personal_access_token_id)
                ->delete();
        }

        $impersonation_session->update([
            'ended_at' => now(),
            'ended_reason' => 'manual',
        ]);

        Log::info('Reseller impersonation ended', ['session_id' => $impersonation_session->id]);

        return response()->json(['message' => 'Impersonation session ended.']);
    }
}
