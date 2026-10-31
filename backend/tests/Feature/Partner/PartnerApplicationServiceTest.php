<?php

namespace Tests\Feature\Partner;

use App\Models\AdminUser;
use App\Models\AffiliateUser;
use App\Models\PartnerApplication;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Services\Auth\AccountOwnerType;
use App\Services\PartnerApplication\PartnerApplicationDocuments;
use App\Services\PartnerApplication\PartnerApplicationService;
use App\Services\PartnerApplication\PartnerApplicationStatus as Status;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** ADR-133 decisions 2–11: the application's status machine, documents and approval. */
class PartnerApplicationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PartnerApplicationService $service;

    private PartnerApplicationDocuments $documents;

    private int $adminId;

    /** What Plunk answers; a test sets a 500 to make every email fail. */
    private ?PromiseInterface $mailResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.plunk.api_key' => 'sk_test', 'services.onboarding.url' => 'https://onboarding.test', 'filesystems.kyc_disk' => 'local']);
        Storage::fake('local');
        Http::fake(fn () => $this->mailResponse ?? Http::response(['success' => true]));
        $this->service = app(PartnerApplicationService::class);
        $this->documents = app(PartnerApplicationDocuments::class);
        $this->adminId = AdminUser::factory()->superAdmin()->create()->id;
    }

    /** @return array<string, mixed> */
    private function answers(array $overrides = []): array
    {
        return array_merge([
            'applicant_type' => PartnerApplication::TYPE_COMPANY,
            'owner_name' => 'Aina',
            'business_name' => 'Aina Gaming Sdn Bhd',
            'email' => 'Aina@Example.test',
            'phone' => '0123456789',
            'experience' => '2 years on Shopee',
            'monthly_sales_range' => '1k_5k',
            'main_games' => 'MLBB',
            'order_channel' => 'api',
            'heard_from' => 'a friend',
        ], $overrides);
    }

    /** The token in the newest email whose link contains `$marker`. */
    private function lastToken(string $marker): string
    {
        $body = Http::recorded()->map(fn ($pair) => $pair[0]['body'])->filter(fn ($b) => str_contains($b, $marker))->last();
        $this->assertNotNull($body, "no email with {$marker}");
        preg_match('#'.preg_quote($marker, '#').'([A-Za-z0-9]{64})#', $body, $m);

        return $m[1];
    }

    private function submittedCompany(): PartnerApplication
    {
        $application = $this->service->inviteDirectly($this->answers(), $this->adminId);
        $this->service->saveStageTwo($application, ['owner_address' => '1 Jalan A', 'business_address' => '2 Jalan B']);
        $this->documents->store($application, 'ssm', UploadedFile::fake()->create('ssm.pdf', 100, 'application/pdf'));
        $this->documents->store($application, 'bank_statement', UploadedFile::fake()->create('bank.pdf', 100, 'application/pdf'));

        return $this->service->submitStageTwo($application);
    }

    private function tier(bool $active = true): ResellerTier
    {
        return ResellerTier::query()->create(['name' => 'T'.uniqid(), 'markup_percent' => 5, 'is_active' => $active, 'sort_order' => 1]);
    }

    public function test_stage_one_waits_for_the_email_link_then_counts_as_new(): void
    {
        $this->service->submitStageOne($this->answers());

        $application = PartnerApplication::query()->sole();
        $this->assertSame(Status::EmailPending, $application->status);
        $this->assertSame('aina@example.test', $application->email);
        $this->assertNotNull($application->stage1_consented_at);

        $this->assertTrue($this->service->verifyEmail($this->lastToken('/apply/verify?token=')));
        $this->assertSame(Status::New, $application->fresh()->status);
        $this->assertNotNull($application->fresh()->email_verified_at);
    }

    public function test_sending_stage_one_again_before_confirming_refreshes_it_and_revokes_the_old_link(): void
    {
        $this->service->submitStageOne($this->answers());
        $first = $this->lastToken('/apply/verify?token=');

        $this->service->submitStageOne($this->answers(['owner_name' => 'Aina B']));

        $this->assertSame(1, PartnerApplication::query()->count());
        $this->assertSame('Aina B', PartnerApplication::query()->sole()->owner_name);
        $this->assertFalse($this->service->verifyEmail($first));
        $this->assertTrue($this->service->verifyEmail($this->lastToken('/apply/verify?token=')));
    }

    public function test_an_expired_email_link_does_nothing(): void
    {
        $this->service->submitStageOne($this->answers());
        $token = $this->lastToken('/apply/verify?token=');

        $this->travel(PartnerApplicationService::VERIFY_HOURS + 1)->hours();

        $this->assertFalse($this->service->verifyEmail($token));
    }

    public function test_an_email_with_a_portal_login_or_an_open_application_is_refused(): void
    {
        AffiliateUser::query()->create([
            'owner_type' => AccountOwnerType::Reseller->value, 'owner_id' => 1, 'name' => 'X', 'email' => 'Taken@Example.test', 'is_active' => false,
        ]);
        try {
            $this->service->submitStageOne($this->answers(['email' => 'taken@example.test']));
            $this->fail('a closed portal login must still block the email');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        $this->service->submitStageOne($this->answers());
        $this->service->verifyEmail($this->lastToken('/apply/verify?token='));

        $this->expectException(ValidationException::class);
        $this->service->submitStageOne($this->answers());
    }

    public function test_a_failed_email_leaves_no_application(): void
    {
        $this->mailResponse = Http::response('down', 500);

        try {
            $this->service->submitStageOne($this->answers());
        } catch (\Throwable) {
        }

        $this->assertSame(0, PartnerApplication::query()->count());
    }

    public function test_regenerating_the_stage_two_link_revokes_the_old_one(): void
    {
        $application = $this->service->inviteDirectly($this->answers(), $this->adminId);
        $old = $this->lastToken('/onboard/');
        $this->assertNotNull($this->service->findByStageTwoToken($old));

        $this->service->sendStageTwoLink($application);

        $this->assertNull($this->service->findByStageTwoToken($old));
        $this->assertNotNull($this->service->findByStageTwoToken($this->lastToken('/onboard/')));
    }

    public function test_the_stage_two_link_expires_after_fourteen_days(): void
    {
        $this->service->inviteDirectly($this->answers(), $this->adminId);
        $token = $this->lastToken('/onboard/');

        $this->travel(PartnerApplicationService::STAGE_TWO_DAYS)->days();
        $this->travel(1)->minutes();

        $this->assertNull($this->service->findByStageTwoToken($token));
    }

    public function test_submit_needs_each_required_document_for_the_applicant_type(): void
    {
        $application = $this->service->inviteDirectly($this->answers(['applicant_type' => PartnerApplication::TYPE_INDIVIDUAL]), $this->adminId);
        $this->service->saveStageTwo($application, ['owner_address' => '1 Jalan A']);
        $this->documents->store($application, 'ic_front', UploadedFile::fake()->image('front.jpg'));

        try {
            $this->service->submitStageTwo($application);
            $this->fail('missing documents must block submit');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['documents.ic_back', 'documents.bank_statement'], array_keys($e->errors()));
        }

        $this->documents->store($application, 'ic_back', UploadedFile::fake()->image('back.jpg'));
        $this->documents->store($application, 'bank_statement', UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'));
        $submitted = $this->service->submitStageTwo($application);

        $this->assertSame(Status::DocumentsSubmitted, $submitted->status);
        $this->assertNull($submitted->token_hash);
        $this->assertNotNull($submitted->stage2_consented_at);
    }

    public function test_document_kinds_and_limits(): void
    {
        $application = $this->service->inviteDirectly($this->answers(), $this->adminId);

        $first = $this->documents->store($application, 'ssm', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
        $second = $this->documents->store($application, 'ssm', UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'));
        $this->assertSame([$second->id], $application->documents()->pluck('id')->all(), 'a single-file kind replaces the earlier file');
        Storage::disk('local')->assertMissing($first->path);
        Storage::disk('local')->assertExists($second->path);

        $this->documents->store($application, 'bank_statement', UploadedFile::fake()->create('1.pdf', 10, 'application/pdf'));
        $this->documents->store($application, 'bank_statement', UploadedFile::fake()->create('2.pdf', 10, 'application/pdf'));
        try {
            $this->documents->store($application, 'bank_statement', UploadedFile::fake()->create('3.pdf', 10, 'application/pdf'));
            $this->fail('a third bank statement must be refused');
        } catch (ValidationException) {
        }

        $this->expectException(ValidationException::class);
        $this->documents->store($application, 'ic_front', UploadedFile::fake()->image('ic.jpg'));
    }

    public function test_approve_makes_one_reseller_with_the_tier_and_a_login_then_invites(): void
    {
        $application = $this->submittedCompany();
        $tier = $this->tier();

        $reseller = $this->service->approve($application, $tier->id, $this->adminId);

        $this->assertSame($tier->id, $reseller->reseller_tier_id);
        $this->assertSame('Aina Gaming Sdn Bhd', $reseller->business_name);
        $this->assertDatabaseHas('affiliate_users', ['owner_type' => AccountOwnerType::Reseller->value, 'owner_id' => $reseller->id, 'email' => 'aina@example.test']);
        $fresh = $application->fresh();
        $this->assertSame(Status::Approved, $fresh->status);
        $this->assertSame($reseller->id, $fresh->reseller_id);
        $this->assertNull($fresh->active_email);
        Http::assertSent(fn ($r) => $r['to'] === 'aina@example.test' && str_contains($r['body'], 'set-password'));

        $this->expectException(ValidationException::class);
        $this->service->approve($application, $tier->id, $this->adminId);
    }

    public function test_a_failed_invite_does_not_undo_the_approval(): void
    {
        $application = $this->submittedCompany();
        $this->mailResponse = Http::response('down', 500);

        $reseller = $this->service->approve($application, $this->tier()->id, $this->adminId);

        $this->assertSame(Status::Approved, $application->fresh()->status);
        $this->assertTrue(Reseller::query()->whereKey($reseller->id)->exists());
    }

    public function test_approve_refuses_an_inactive_tier_and_changes_nothing(): void
    {
        $application = $this->submittedCompany();

        try {
            $this->service->approve($application, $this->tier(active: false)->id, $this->adminId);
            $this->fail('an inactive tier must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reseller_tier_id', $e->errors());
        }

        $this->assertSame(Status::DocumentsSubmitted, $application->fresh()->status);
        $this->assertSame(0, Reseller::query()->count());
    }

    public function test_decline_emails_a_confirmed_applicant_once_and_frees_the_email(): void
    {
        $application = $this->submittedCompany();
        $before = Http::recorded()->count();

        $this->service->decline($application, $this->adminId);

        $this->assertSame(Status::Declined, $application->fresh()->status);
        $this->assertSame($before + 1, Http::recorded()->count());
        try {
            $this->service->decline($application, $this->adminId);
            $this->fail('declining twice must be refused');
        } catch (ValidationException) {
        }
        $this->assertSame($before + 1, Http::recorded()->count());

        $this->service->submitStageOne($this->answers());
        $this->assertSame(2, PartnerApplication::query()->count());
    }

    public function test_declining_an_unconfirmed_email_sends_nothing(): void
    {
        $this->service->submitStageOne($this->answers());
        $before = Http::recorded()->count();

        $this->service->decline(PartnerApplication::query()->sole(), $this->adminId);

        $this->assertSame($before, Http::recorded()->count());
    }

    public function test_delete_data_removes_the_files_and_the_row_but_keeps_the_reseller(): void
    {
        $application = $this->submittedCompany();
        $paths = $application->documents()->pluck('path')->all();
        $reseller = $this->service->approve($application, $this->tier()->id, $this->adminId);

        $this->service->deleteData($application);

        $this->assertSame(0, PartnerApplication::query()->count());
        $this->assertDatabaseCount('partner_application_documents', 0);
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $this->assertTrue(Reseller::query()->whereKey($reseller->id)->exists());
    }
}
