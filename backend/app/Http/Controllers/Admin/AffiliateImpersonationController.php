<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImpersonateAffiliateRequest;
use App\Models\Affiliate;
use App\Models\AffiliateImpersonationSession;
use App\Models\AffiliateUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ADR-058 58b (RES-4): a super_admin opens an impersonation session for
 * an affiliate portal (ADR-059). We mint a short-lived `affiliate`-guard
 * Sanctum token, scoped with an `impersonate` ability, against one of
 * the affiliate's active portal users. The session runs under the real
 * `affiliate` guard, so ADR-057's tenant scope applies unchanged — an
 * impersonating admin sees exactly what the affiliate sees.
 *
 * Every session is audited: `affiliate_impersonation_sessions` gets a row
 * on start (real admin identity, portal identity, token id, IP) and it
 * is closed on end / token revocation / affiliate deactivation. The
 * persistent "Impersonating {affiliate} — acting as {admin}" banner and
 * per-request tagging are the portal's job (ADR-059), which reads the
 * token's `impersonate` ability + this table.
 */
class AffiliateImpersonationController extends Controller
{
    /** Minted tokens live this long — long enough for a debugging session, short enough to not linger. */
    private const TOKEN_TTL_MINUTES = 60;

    public function index(): JsonResponse
    {
        $sessions = AffiliateImpersonationSession::query()
            ->with(['affiliate:id,business_name', 'admin:id,name', 'affiliateUser:id,name,email'])
            ->orderByDesc('started_at')
            ->limit(100)
            ->get()
            ->map(fn (AffiliateImpersonationSession $s) => [
                'id' => $s->id,
                'affiliate' => $s->affiliate?->business_name,
                'affiliate_id' => $s->affiliate_id,
                'admin' => $s->admin?->name,
                'acting_as' => $s->affiliateUser?->email,
                'reason' => $s->reason,
                'ip' => $s->ip,
                'started_at' => $s->started_at,
                'ended_at' => $s->ended_at,
                'ended_reason' => $s->ended_reason,
                'active' => $s->isActive(),
            ]);

        return response()->json(['sessions' => $sessions]);
    }

    public function store(ImpersonateAffiliateRequest $request, Affiliate $affiliate): JsonResponse
    {
        if ($affiliate->status !== 'active') {
            throw ValidationException::withMessages([
                'affiliate' => ['This affiliate is not active — reactivate it before impersonating.'],
            ]);
        }

        $target = $affiliate->users()
            ->where('is_active', true)
            ->orderByRaw('password is null')  // prefer a user who has accepted their invite
            ->orderBy('id')
            ->first();

        if (! $target instanceof AffiliateUser) {
            throw ValidationException::withMessages([
                'affiliate' => ['This affiliate has no active portal user to impersonate. Add one first.'],
            ]);
        }

        $expiresAt = now()->addMinutes(self::TOKEN_TTL_MINUTES);
        $newToken = $target->createToken(
            "impersonation:admin#{$request->user()->id}",
            ['impersonate'],
            $expiresAt,
        );

        $session = AffiliateImpersonationSession::query()->create([
            'affiliate_id' => $affiliate->id,
            'admin_user_id' => $request->user()->id,
            'affiliate_user_id' => $target->id,
            'personal_access_token_id' => $newToken->accessToken->id,
            'reason' => $request->validated('reason'),
            'ip' => $request->ip(),
            'started_at' => now(),
        ]);

        Log::info('Affiliate impersonation started', [
            'session_id' => $session->id,
            'affiliate_id' => $affiliate->id,
            'admin_user_id' => $request->user()->id,
            'affiliate_user_id' => $target->id,
        ]);

        return response()->json([
            'session_id' => $session->id,
            'token' => $newToken->plainTextToken,
            'acting_as' => $target->only(['id', 'name', 'email']),
            // Deliberately 'reseller_portal' — see AffiliateInviteService's
            // own comment on this same config-key gotcha.
            'portal_url' => rtrim((string) config('services.reseller_portal.url'), '/'),
            'expires_at' => $expiresAt,
        ], 201);
    }

    public function end(AffiliateImpersonationSession $impersonation_session): JsonResponse
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

        Log::info('Affiliate impersonation ended', ['session_id' => $impersonation_session->id]);

        return response()->json(['message' => 'Impersonation session ended.']);
    }
}
