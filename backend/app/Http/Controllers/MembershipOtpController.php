<?php

namespace App\Http\Controllers;

use App\Http\Requests\Membership\SendOtpRequest;
use App\Http\Requests\Membership\VerifyOtpRequest;
use App\Services\Membership\MembershipSessionTokenService;
use App\Services\Membership\OtpService;
use App\Services\Membership\PlunkMailer;
use App\Support\StorefrontBrand;
use Illuminate\Http\JsonResponse;

/**
 * ADR-027's 2026-08-29 addendum, decisions 23/26/27: identity
 * verification — email + OTP, no login/account exists (ADR-011's
 * guest-checkout model, narrowed rather than reversed by decision 2's
 * own framing). Guest-callable, no auth:sanctum, same trust model as
 * CheckoutController/CatalogController.
 *
 * ADR-061 decision 5: OTP identity is per-brand. The storefront brand
 * is resolved server-side per `Host` (ADR-060, `storefront.brand`
 * middleware; `Affiliate::primary()` with no `X-Storefront-Host`) —
 * never from a request field — and both the issued code and the session
 * token are scoped to it.
 */
class MembershipOtpController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly PlunkMailer $mailer,
        private readonly MembershipSessionTokenService $sessionTokens,
        private readonly StorefrontBrand $brand,
    ) {}

    public function send(SendOtpRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $code = $this->otp->generate($this->brand->get()->id, $email);
        $this->mailer->sendOtpEmail($email, $code);

        return response()->json(['message' => 'Verification code sent.']);
    }

    /**
     * Decision 23: on success, issues the 30-day session token — the
     * storefront stores this client-side and stops needing OTP again
     * until it lapses.
     */
    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $code = $request->validated('code');
        $affiliateId = $this->brand->get()->id;

        if (! $this->otp->verify($affiliateId, $email, $code)) {
            return response()->json(['message' => 'That code is invalid or has expired.'], 422);
        }

        return response()->json(['token' => $this->sessionTokens->issue($affiliateId, $email)]);
    }
}
