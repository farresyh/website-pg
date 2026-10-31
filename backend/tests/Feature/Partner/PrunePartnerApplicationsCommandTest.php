<?php

namespace Tests\Feature\Partner;

use App\Models\PartnerApplication;
use App\Models\Reseller;
use App\Services\PartnerApplication\PartnerApplicationStatus as Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** ADR-133 decision 9: the retention the PDPA notices promise, and nothing earlier. */
class PrunePartnerApplicationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function application(Status $status, array $attributes = []): PartnerApplication
    {
        $application = PartnerApplication::query()->create(array_merge([
            'status' => $status, 'applicant_type' => 'company', 'owner_name' => 'A', 'email' => uniqid().'@example.test', 'phone' => '1',
        ], $attributes));
        Storage::disk('local')->put("kyc/{$application->id}/f.pdf", 'x');
        $application->documents()->create(['kind' => 'ssm', 'disk' => 'local', 'path' => "kyc/{$application->id}/f.pdf", 'original_name' => 'f.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1]);

        return $application;
    }

    private function reseller(?string $closedAt): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => 'R'.uniqid(), 'is_active' => $closedAt === null]);
        if ($closedAt !== null) {
            $reseller->deleted_at = $closedAt;
            $reseller->save();
        }

        return $reseller;
    }

    public function test_it_deletes_exactly_what_is_past_its_retention_and_a_second_run_does_nothing(): void
    {
        Storage::fake('local');

        $declinedOld = $this->application(Status::Declined, ['decided_at' => now()->subDays(91)]);
        $declinedNew = $this->application(Status::Declined, ['decided_at' => now()->subDays(89)]);
        $closedOld = $this->application(Status::Approved, ['reseller_id' => $this->reseller(now()->subDays(91))->id]);
        $closedNew = $this->application(Status::Approved, ['reseller_id' => $this->reseller(now()->subDays(89))->id]);
        $openAccount = $this->application(Status::Approved, ['reseller_id' => $this->reseller(null)->id]);
        $submitted = $this->application(Status::DocumentsSubmitted, ['submitted_at' => now()->subDays(200)]);
        $unconfirmedOld = $this->application(Status::EmailPending);
        PartnerApplication::query()->whereKey($unconfirmedOld->id)->update(['updated_at' => now()->subDays(8)]);
        $unconfirmedNew = $this->application(Status::EmailPending);

        $this->artisan('app:prune-partner-applications')->assertSuccessful();

        $gone = [$declinedOld, $closedOld, $unconfirmedOld];
        foreach ($gone as $application) {
            $this->assertDatabaseMissing('partner_applications', ['id' => $application->id]);
            Storage::disk('local')->assertMissing("kyc/{$application->id}/f.pdf");
        }
        foreach ([$declinedNew, $closedNew, $openAccount, $submitted, $unconfirmedNew] as $application) {
            $this->assertDatabaseHas('partner_applications', ['id' => $application->id]);
            Storage::disk('local')->assertExists("kyc/{$application->id}/f.pdf");
        }
        $this->assertSame(3, Reseller::withTrashed()->count(), 'the reseller accounts themselves are never deleted');

        $this->artisan('app:prune-partner-applications')->expectsOutputToContain('Deleted 0')->assertSuccessful();
    }
}
