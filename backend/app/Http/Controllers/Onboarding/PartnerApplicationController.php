<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SaveStageTwoRequest;
use App\Http\Requests\Onboarding\StoreStageOneRequest;
use App\Http\Requests\Onboarding\SubmitStageTwoRequest;
use App\Http\Requests\Onboarding\UploadDocumentRequest;
use App\Http\Requests\Onboarding\VerifyEmailRequest;
use App\Models\PartnerApplication;
use App\Models\PartnerApplicationDocument;
use App\Models\PlatformSettings;
use App\Services\PartnerApplication\PartnerApplicationDocuments;
use App\Services\PartnerApplication\PartnerApplicationNotice;
use App\Services\PartnerApplication\PartnerApplicationService;
use App\Services\PlayerCheck\TurnstileVerifier;
use Illuminate\Http\JsonResponse;

/**
 * ADR-133 decisions 3–8: the public reseller application, no login. Stage 1 is behind the admin's
 * "Public applications open" switch, a throttle and Turnstile; stage 2 is reached only through the
 * single-use link emailed to the verified address, so it needs neither. Responses carry the
 * applicant's own answers only, never a storage path.
 */
class PartnerApplicationController extends Controller
{
    public function __construct(
        private readonly PartnerApplicationService $applications,
        private readonly PartnerApplicationDocuments $documents,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json([
            'open' => PlatformSettings::current()->partner_applications_open,
            'notice' => PartnerApplicationNotice::stage(1),
        ]);
    }

    public function store(StoreStageOneRequest $request, TurnstileVerifier $turnstile): JsonResponse
    {
        if (! PlatformSettings::current()->partner_applications_open) {
            return response()->json(['message' => 'Applications are not open right now.', 'reason' => 'applications_closed'], 403);
        }
        if (! $turnstile->isConfigured()) {
            return response()->json(['message' => 'Applications are not available right now.', 'reason' => 'verification_unavailable'], 503);
        }

        $data = $request->validated();
        if (! $turnstile->verify($data['turnstile_token'], $request->ip())) {
            return response()->json(['message' => 'Please complete the verification and try again.', 'reason' => 'turnstile_failed'], 422);
        }

        $this->applications->submitStageOne($data);

        return response()->json(['message' => 'Check your email for a link to confirm your application.'], 202);
    }

    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        if (! $this->applications->verifyEmail($request->validated('token'))) {
            return response()->json(['message' => 'This link has expired or was already used.', 'reason' => 'link_invalid'], 422);
        }

        return response()->json(['message' => 'Thanks, your email is confirmed. We will review your application and contact you.']);
    }

    public function show(string $token): JsonResponse
    {
        return response()->json($this->shape($this->resolve($token)));
    }

    public function update(SaveStageTwoRequest $request, string $token): JsonResponse
    {
        return response()->json($this->shape($this->applications->saveStageTwo($this->resolve($token), $request->validated())));
    }

    public function upload(UploadDocumentRequest $request, string $token): JsonResponse
    {
        $application = $this->resolve($token);
        $this->documents->store($application, $request->validated('kind'), $request->file('file'));

        return response()->json($this->shape($application->fresh()), 201);
    }

    public function destroyDocument(string $token, int $document): JsonResponse
    {
        $application = $this->resolve($token);
        $this->documents->delete($application->documents()->findOrFail($document));

        return response()->json($this->shape($application->fresh()));
    }

    public function submit(SubmitStageTwoRequest $request, string $token): JsonResponse
    {
        $this->applications->submitStageTwo($this->resolve($token));

        return response()->json(['message' => 'Thanks, your documents are submitted. We will review them and email you.']);
    }

    private function resolve(string $token): PartnerApplication
    {
        $application = strlen($token) === 64 ? $this->applications->findByStageTwoToken($token) : null;
        abort_if($application === null, 404, 'This link has expired or was already used.');

        return $application;
    }

    /** @return array<string, mixed> */
    private function shape(PartnerApplication $application): array
    {
        return [
            'applicant_type' => $application->applicant_type,
            'owner_name' => $application->owner_name,
            'business_name' => $application->business_name,
            'email' => $application->email,
            'owner_address' => $application->owner_address,
            'business_address' => $application->business_address,
            'tax_number' => $application->tax_number,
            'expires_at' => $application->token_expires_at?->toISOString(),
            'required_documents' => PartnerApplicationDocuments::REQUIRED[$application->applicant_type],
            'document_limits' => PartnerApplicationDocuments::LIMITS,
            'documents' => $application->documents()->orderBy('id')->get()
                ->map(fn (PartnerApplicationDocument $d) => ['id' => $d->id, 'kind' => $d->kind, 'original_name' => $d->original_name, 'size_bytes' => $d->size_bytes])
                ->all(),
            'notice' => PartnerApplicationNotice::stage(2),
        ];
    }
}
