<?php

namespace App\Http\Controllers;

use App\Http\Requests\Membership\SendOtpRequest;
use App\Http\Requests\Membership\VerifyOtpRequest;
use App\Services\Membership\MembershipSessionTokenService;
use App\Services\Membership\OtpService;
use App\Services\Membership\PlunkMailer;
use Illuminate\Http\JsonResponse;

/**
 * ADR-027's 2026-08-29 addendum, decisions 23/26/27: identity
 * verification — email + OTP, no login/account exists (ADR-011's
 * guest-checkout model, narrowed rather than reversed by decision 2's
 * own framing). Guest-callable, no auth:sanctum, same trust model as
 * CheckoutController/CatalogController.
 */
class MembershipOtpController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly PlunkMailer $mailer,
        private readonly MembershipSessionTokenService $sessionTokens,
    ) {
    }

    public function send(SendOtpRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $code = $this->otp->generate($email);
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

        if (! $this->otp->verify($email, $code)) {
            return response()->json(['message' => 'That code is invalid or has expired.'], 422);
        }

        return response()->json(['token' => $this->sessionTokens->issue($email)]);
    }
}
