<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApprovePartnerApplicationRequest;
use App\Http\Requests\Admin\InvitePartnerApplicantRequest;
use App\Http\Requests\Admin\UpdatePartnerApplicationSettingsRequest;
use App\Models\AdminUser;
use App\Models\PartnerApplication;
use App\Models\PartnerApplicationDocument;
use App\Models\PartnerDocumentView;
use App\Models\PlatformSettings;
use App\Services\PartnerApplication\PartnerApplicationService;
use App\Services\PartnerApplication\PartnerApplicationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-133 decision 6: the admin's applications screen. Every status change goes through
 * `PartnerApplicationService`. A KYC file is opened through a signed link valid for 5 minutes,
 * and each opening is recorded with the admin (decision 8).
 */
class PartnerApplicationController extends Controller
{
    public const FILE_LINK_MINUTES = 5;

    public function __construct(private readonly PartnerApplicationService $applications) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::enum(PartnerApplicationStatus::class)]]);

        $page = PartnerApplication::query()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->withCount('documents')
            ->latest('id')
            ->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (PartnerApplication $a) => $this->rowShape($a))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => PartnerApplication::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(PartnerApplication $partnerApplication): JsonResponse
    {
        return response()->json($this->detailShape($partnerApplication));
    }

    public function invite(InvitePartnerApplicantRequest $request): JsonResponse
    {
        $application = $this->applications->inviteDirectly($request->validated(), $request->user()->id);

        return response()->json($this->detailShape($application), 201);
    }

    /** Send stage-2 link (`new`), or Regenerate link (`invited`). */
    public function sendLink(PartnerApplication $partnerApplication): JsonResponse
    {
        return response()->json($this->detailShape($this->applications->sendStageTwoLink($partnerApplication)));
    }

    public function decline(Request $request, PartnerApplication $partnerApplication): JsonResponse
    {
        return response()->json($this->detailShape($this->applications->decline($partnerApplication, $request->user()->id)));
    }

    public function approve(ApprovePartnerApplicationRequest $request, PartnerApplication $partnerApplication): JsonResponse
    {
        $reseller = $this->applications->approve($partnerApplication, (int) $request->validated('reseller_tier_id'), $request->user()->id);
        Log::info('Partner application approved', ['application_id' => $partnerApplication->id, 'reseller_id' => $reseller->id, 'admin_user_id' => $request->user()->id]);

        return response()->json($this->detailShape($partnerApplication->fresh()));
    }

    /** Delete applicant data: a data-subject request. Behind step-up. */
    public function destroy(Request $request, PartnerApplication $partnerApplication): JsonResponse
    {
        $this->applications->deleteData($partnerApplication);
        Log::info('Partner application data deleted', ['application_id' => $partnerApplication->id, 'admin_user_id' => $request->user()->id]);

        return response()->json(null, 204);
    }

    public function fileLink(Request $request, PartnerApplication $partnerApplication, PartnerApplicationDocument $document): JsonResponse
    {
        abort_unless($document->partner_application_id === $partnerApplication->id, 404);

        $url = URL::temporarySignedRoute('partner-documents.file', now()->addMinutes(self::FILE_LINK_MINUTES), [
            'document' => $document->id,
            'admin_id' => $request->user()->id,
        ]);

        return response()->json(['url' => $url]);
    }

    /** The signed link's target. The admin is re-checked here: the signature only proves the link is untouched. */
    public function file(Request $request, PartnerApplicationDocument $document): StreamedResponse
    {
        $admin = AdminUser::query()->find($request->query('admin_id'));
        abort_unless($admin !== null && $admin->is_active && $admin->role === 'super_admin', 403);

        PartnerDocumentView::query()->create(['document_id' => $document->id, 'admin_user_id' => $admin->id, 'viewed_at' => now()]);

        return Storage::disk($document->disk)->response($document->path, $document->original_name, [
            'Content-Type' => $document->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    public function settings(): JsonResponse
    {
        return response()->json(['partner_applications_open' => PlatformSettings::current()->partner_applications_open]);
    }

    public function updateSettings(UpdatePartnerApplicationSettingsRequest $request): JsonResponse
    {
        $settings = PlatformSettings::current();
        $settings->update(['partner_applications_open' => $request->boolean('partner_applications_open')]);
        Log::info('Partner applications switch changed', ['open' => $settings->partner_applications_open, 'admin_user_id' => $request->user()->id]);

        return response()->json(['partner_applications_open' => $settings->partner_applications_open]);
    }

    /** @return array<string, mixed> */
    private function rowShape(PartnerApplication $a): array
    {
        return [
            'id' => $a->id,
            'status' => $a->status->value,
            'source' => $a->source,
            'applicant_type' => $a->applicant_type,
            'owner_name' => $a->owner_name,
            'business_name' => $a->business_name,
            'email' => $a->email,
            'phone' => $a->phone,
            'monthly_sales_range' => $a->monthly_sales_range,
            'documents_count' => $a->documents_count ?? $a->documents()->count(),
            'created_at' => $a->created_at?->toISOString(),
            'submitted_at' => $a->submitted_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function detailShape(PartnerApplication $a): array
    {
        $a->load(['documents.views.admin:id,name', 'reseller:id,business_name,deleted_at']);

        return $this->rowShape($a) + [
            'experience' => $a->experience,
            'main_games' => $a->main_games,
            'order_channel' => $a->order_channel,
            'heard_from' => $a->heard_from,
            'email_verified_at' => $a->email_verified_at?->toISOString(),
            'stage1_notice_version' => $a->stage1_notice_version,
            'stage1_consented_at' => $a->stage1_consented_at?->toISOString(),
            'link_expires_at' => $a->token_expires_at?->toISOString(),
            'owner_address' => $a->owner_address,
            'business_address' => $a->business_address,
            'tax_number' => $a->tax_number,
            'stage2_notice_version' => $a->stage2_notice_version,
            'stage2_consented_at' => $a->stage2_consented_at?->toISOString(),
            'decided_at' => $a->decided_at?->toISOString(),
            'reseller' => $a->reseller ? ['id' => $a->reseller->id, 'business_name' => $a->reseller->business_name, 'closed' => $a->reseller->deleted_at !== null] : null,
            'documents' => $a->documents->map(fn (PartnerApplicationDocument $d) => [
                'id' => $d->id,
                'kind' => $d->kind,
                'original_name' => $d->original_name,
                'mime' => $d->mime,
                'size_bytes' => $d->size_bytes,
                'views' => $d->views->sortByDesc('viewed_at')->values()->map(fn (PartnerDocumentView $v) => ['admin' => $v->admin?->name, 'viewed_at' => $v->viewed_at->toISOString()])->all(),
            ])->all(),
        ];
    }
}
