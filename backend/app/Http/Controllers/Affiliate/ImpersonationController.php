<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\AffiliateImpersonationSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ADR-059 59c: the portal's own "Exit impersonation" — an impersonation
 * token closes its own session and revokes itself. Mirrors
 * `Admin\AffiliateImpersonationController::end()` (which an admin uses
 * from `/admin/affiliates`); this is the same close, reachable from the
 * banner inside the portal so the admin doesn't have to switch tabs.
 * Only a token with the `impersonate` ability can call it.
 */
class ImpersonationController extends Controller
{
    public function end(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // The signal is "an open impersonation session exists for this
        // exact token" — not the token's ability list. A normal
        // portal-login token (`['*']` abilities) technically `can()`
        // anything, so keying on the session row is the unambiguous
        // check.
        $session = $token === null ? null : AffiliateImpersonationSession::query()
            ->where('personal_access_token_id', $token->getKey())
            ->whereNull('ended_at')
            ->first();

        if ($session === null) {
            throw new HttpException(403, 'Not an impersonation session.');
        }

        $session->update(['ended_at' => now(), 'ended_reason' => 'manual']);
        Log::info('Affiliate impersonation ended from portal', ['session_id' => $session->id]);

        DB::table('personal_access_tokens')->where('id', $token->getKey())->delete();

        return response()->json(['message' => 'Impersonation session ended.']);
    }
}
