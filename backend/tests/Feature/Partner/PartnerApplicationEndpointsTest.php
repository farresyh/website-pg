<?php

namespace Tests\Feature\Partner;

use App\Http\Controllers\Admin\PartnerApplicationController;
use App\Models\AdminUser;
use App\Models\PartnerApplication;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Services\PartnerApplication\PartnerApplicationNotice;
use App\Services\PartnerApplication\PartnerApplicationStatus as Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ADR-133 decisions 3–8 and 6: the public application endpoints and the admin screen's API. */
class PartnerApplicationEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private bool $turnstilePasses = true;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.plunk.api_key' => 'sk_test',
            'services.onboarding.url' => 'https://onboarding.test',
            'services.turnstile.secret_key' => 'ts_secret',
            'filesystems.kyc_disk' => 'local',
        ]);
        Storage::fake('local');
        Http::fake(fn (HttpRequest $r) => str_contains($r->url(), 'turnstile')
            ? Http::response(['success' => $this->turnstilePasses])
            : Http::response(['success' => true]));
    }

    private function open(bool $open = true): void
    {
        PlatformSettings::current()->update(['partner_applications_open' => $open]);
    }

    /** @return array<string, mixed> */
    private function stageOne(array $overrides = []): array
    {
        return array_merge([
            'applicant_type' => 'company', 'owner_name' => 'Aina', 'business_name' => 'Aina Gaming',
            'email' => 'aina@example.test', 'phone' => '0123456789', 'monthly_sales_range' => '1k_5k',
            'order_channel' => 'api', 'consent' => true, 'turnstile_token' => 'tok',
        ], $overrides);
    }

    private function lastToken(string $marker): string
    {
        $body = Http::recorded()->map(fn ($pair) => $pair[0]['body'] ?? '')->filter(fn ($b) => is_string($b) && str_contains($b, $marker))->last();
        preg_match('#'.preg_quote($marker, '#').'([A-Za-z0-9]{64})#', (string) $body, $m);

        return $m[1];
    }

    private function admin(string $role = 'super_admin'): AdminUser
    {
        $admin = AdminUser::factory()->create(['role' => $role]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function invitedToken(string $type = 'company'): string
    {
        $this->admin();
        $this->postJson('/api/partner-applications/invite', [
            'applicant_type' => $type, 'owner_name' => 'Aina', 'business_name' => $type === 'company' ? 'Aina Gaming' : null,
            'email' => 'aina@example.test', 'phone' => '0123',
        ])->assertCreated();

        return $this->lastToken('/onboard/');
    }

    private function submitCompany(string $token): void
    {
        $this->putJson("/api/onboarding/stage-two/{$token}", ['owner_address' => '1 Jalan A', 'business_address' => '2 Jalan B'])->assertOk();
        $this->post("/api/onboarding/stage-two/{$token}/documents", ['kind' => 'ssm', 'file' => UploadedFile::fake()->create('ssm.pdf', 50, 'application/pdf')])->assertCreated();
        $this->post("/api/onboarding/stage-two/{$token}/documents", ['kind' => 'bank_statement', 'file' => UploadedFile::fake()->create('b.pdf', 50, 'application/pdf')])->assertCreated();
        $this->postJson("/api/onboarding/stage-two/{$token}/submit", ['consent' => true])->assertOk();
    }

    // ---- public -----------------------------------------------------------

    public function test_status_is_closed_by_default_and_carries_the_bilingual_notice(): void
    {
        $this->getJson('/api/onboarding/status')
            ->assertOk()
            ->assertJsonPath('open', false)
            ->assertJsonPath('notice.version', PartnerApplicationNotice::VERSION)
            ->assertJson(fn ($json) => $json->where('notice.en', fn ($t) => str_contains($t, 'payment service providers'))
                ->where('notice.ms', fn ($t) => str_contains($t, 'penyedia perkhidmatan pembayaran'))->etc());
    }

    public function test_the_notice_names_the_company_from_settings(): void
    {
        PlatformSettings::current()->update(['company_legal_name' => 'LWF Group Sdn Bhd', 'company_reg_no' => '123-X', 'company_address' => '1 Jalan KL']);

        $notice = PartnerApplicationNotice::stage(2);

        $this->assertStringContainsString('LWF Group Sdn Bhd (123-X), operator of PekanGame (1 Jalan KL), collects', $notice['en']);
        $this->assertStringContainsString('LWF Group Sdn Bhd (123-X), pengendali PekanGame (1 Jalan KL), mengumpul', $notice['ms']);
    }

    public function test_stage_one_is_refused_while_applications_are_closed(): void
    {
        $this->postJson('/api/onboarding/applications', $this->stageOne())
            ->assertForbidden()->assertJsonPath('reason', 'applications_closed');
        $this->assertSame(0, PartnerApplication::query()->count());
    }

    public function test_stage_one_needs_turnstile_and_then_waits_for_the_email(): void
    {
        $this->open();

        $this->turnstilePasses = false;
        $this->postJson('/api/onboarding/applications', $this->stageOne())->assertStatus(422)->assertJsonPath('reason', 'turnstile_failed');

        $this->turnstilePasses = true;
        $this->postJson('/api/onboarding/applications', $this->stageOne())->assertStatus(202);

        $this->postJson('/api/onboarding/verify-email', ['token' => str_repeat('x', 64)])->assertStatus(422);
        $this->postJson('/api/onboarding/verify-email', ['token' => $this->lastToken('/apply/verify?token=')])->assertOk();
        $this->assertSame(Status::New, PartnerApplication::query()->sole()->status);
    }

    public function test_stage_one_without_a_turnstile_secret_is_unavailable(): void
    {
        $this->open();
        config(['services.turnstile.secret_key' => null]);

        $this->postJson('/api/onboarding/applications', $this->stageOne())->assertStatus(503);
    }

    public function test_stage_one_validation(): void
    {
        $this->open();

        $this->postJson('/api/onboarding/applications', $this->stageOne(['business_name' => null, 'consent' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors(['business_name', 'consent']);

        $this->postJson('/api/onboarding/applications', $this->stageOne(['applicant_type' => 'individual', 'business_name' => null]))
            ->assertStatus(202);
    }

    public function test_stage_two_round_trip_never_exposes_a_storage_path(): void
    {
        $token = $this->invitedToken();

        $this->getJson('/api/onboarding/stage-two/'.str_repeat('a', 64))->assertNotFound();
        $response = $this->post("/api/onboarding/stage-two/{$token}/documents", ['kind' => 'ssm', 'file' => UploadedFile::fake()->create('ssm.pdf', 50, 'application/pdf')])
            ->assertCreated()
            ->assertJsonPath('documents.0.kind', 'ssm')
            ->assertJsonPath('required_documents', ['ssm', 'bank_statement']);
        $this->assertStringNotContainsString('kyc/', $response->getContent());

        $this->submitCompany($token);

        $this->assertSame(Status::DocumentsSubmitted, PartnerApplication::query()->sole()->status);
        $this->getJson("/api/onboarding/stage-two/{$token}")->assertNotFound();
    }

    public function test_upload_checks_the_real_file_type_and_the_size(): void
    {
        $token = $this->invitedToken();

        $this->post("/api/onboarding/stage-two/{$token}/documents", ['kind' => 'ssm', 'file' => UploadedFile::fake()->create('ssm.pdf', 50, 'text/plain')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post("/api/onboarding/stage-two/{$token}/documents", ['kind' => 'ssm', 'file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post("/api/onboarding/stage-two/{$token}/documents", ['kind' => 'ic_front', 'file' => UploadedFile::fake()->image('ic.png')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('kind');
    }

    public function test_a_link_cannot_delete_another_applications_document(): void
    {
        $token = $this->invitedToken();
        $other = PartnerApplication::query()->create([
            'status' => Status::Invited, 'applicant_type' => 'company', 'owner_name' => 'B', 'email' => 'b@example.test', 'phone' => '1',
        ]);
        $doc = $other->documents()->create(['kind' => 'ssm', 'disk' => 'local', 'path' => 'kyc/x.pdf', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1]);

        $this->deleteJson("/api/onboarding/stage-two/{$token}/documents/{$doc->id}")->assertNotFound();
        $this->assertDatabaseHas('partner_application_documents', ['id' => $doc->id]);
    }

    // ---- admin ------------------------------------------------------------

    public function test_only_a_super_admin_reaches_the_admin_api(): void
    {
        $this->admin('admin');

        $this->getJson('/api/partner-applications')->assertForbidden();
    }

    public function test_admin_lists_approves_and_the_reseller_shows_kyc_on_file(): void
    {
        $token = $this->invitedToken();
        $this->submitCompany($token);
        $application = PartnerApplication::query()->sole();
        $tier = ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => 5, 'is_active' => true, 'sort_order' => 1]);

        $this->getJson('/api/partner-applications?status=documents_submitted')
            ->assertOk()->assertJsonPath('data.0.id', $application->id)->assertJsonPath('data.0.documents_count', 2)
            ->assertJsonPath('counts.documents_submitted', 1);

        $this->postJson("/api/partner-applications/{$application->id}/approve", [])->assertUnprocessable();
        $this->postJson("/api/partner-applications/{$application->id}/approve", ['reseller_tier_id' => $tier->id])
            ->assertOk()->assertJsonPath('status', 'approved');

        $reseller = Reseller::query()->sole();
        $this->getJson('/api/resellers')->assertOk()->assertJsonPath('resellers.0.kyc', 'on_file');
        $this->getJson("/api/resellers/{$reseller->id}")->assertOk()->assertJsonPath('kyc', 'on_file');
    }

    public function test_admin_send_link_regenerates_and_decline_frees_the_email(): void
    {
        $token = $this->invitedToken();
        $application = PartnerApplication::query()->sole();

        $this->postJson("/api/partner-applications/{$application->id}/send-link")->assertOk()->assertJsonPath('status', 'invited');
        $this->getJson("/api/onboarding/stage-two/{$token}")->assertNotFound();

        $this->postJson("/api/partner-applications/{$application->id}/decline")->assertOk()->assertJsonPath('status', 'declined');
        $this->postJson("/api/partner-applications/{$application->id}/decline")->assertUnprocessable();
        $this->assertNull($application->fresh()->active_email);
    }

    public function test_a_kyc_file_opens_only_through_a_fresh_signed_link_and_each_view_is_logged(): void
    {
        $token = $this->invitedToken();
        $this->submitCompany($token);
        $application = PartnerApplication::query()->sole();
        $document = $application->documents()->where('kind', 'ssm')->sole();

        $url = $this->postJson("/api/partner-applications/{$application->id}/documents/{$document->id}/link")->assertOk()->json('url');

        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseCount('partner_document_views', 1);
        $this->getJson("/api/partner-applications/{$application->id}")->assertJsonCount(1, 'documents.0.views');

        $this->get($url.'x')->assertForbidden();
        $this->travel(PartnerApplicationController::FILE_LINK_MINUTES + 1)->minutes();
        $this->get($url)->assertForbidden();
        $this->assertDatabaseCount('partner_document_views', 1);
    }

    public function test_a_signed_link_stops_working_for_an_admin_who_was_deactivated(): void
    {
        $token = $this->invitedToken();
        $this->submitCompany($token);
        $application = PartnerApplication::query()->sole();
        $document = $application->documents()->first();
        $admin = $this->admin();

        $url = $this->postJson("/api/partner-applications/{$application->id}/documents/{$document->id}/link")->json('url');
        $admin->update(['is_active' => false]);

        $this->get($url)->assertForbidden();
    }

    public function test_delete_applicant_data_needs_step_up_and_removes_everything(): void
    {
        $token = $this->invitedToken();
        $this->submitCompany($token);
        $application = PartnerApplication::query()->sole();

        $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());
        $this->deleteJson("/api/partner-applications/{$application->id}")->assertNoContent();

        $this->assertSame(0, PartnerApplication::query()->count());
        $this->assertDatabaseCount('partner_application_documents', 0);
    }

    public function test_the_open_switch(): void
    {
        $this->admin();

        $this->getJson('/api/partner-applications/settings')->assertJsonPath('partner_applications_open', false);
        $this->putJson('/api/partner-applications/settings', ['partner_applications_open' => true])->assertJsonPath('partner_applications_open', true);
        $this->getJson('/api/onboarding/status')->assertJsonPath('open', true);
    }
}
