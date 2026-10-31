<?php

namespace Tests\Feature\Partner;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\AffiliateUser;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Auth\AccountOwnerType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-133 decision 17: the portal promises "we will let you know" when payments are approved.
 * One email, to the affiliate's portal logins, only on the move from not approved to approved.
 */
class PaymentsApprovedNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Affiliate $affiliate;

    private AffiliateDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryAffiliate();
        config(['services.plunk.api_key' => 'sk_test']);
        Http::fake();

        $this->affiliate = Affiliate::query()->create(['business_name' => 'Acme', 'email' => 'contact@acme.test', 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active']);
        $this->domain = AffiliateDomain::query()->create([
            'affiliate_id' => $this->affiliate->id, 'hostname' => 'shop.acme.test', 'status' => AffiliateDomainStatus::Active, 'is_primary' => true,
        ]);
        $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());
    }

    private function user(string $email, bool $active = true): void
    {
        AffiliateUser::query()->create([
            'owner_type' => AccountOwnerType::Affiliate->value, 'owner_id' => $this->affiliate->id,
            'name' => 'Owner', 'email' => $email, 'password' => bcrypt('password'), 'is_active' => $active,
        ]);
    }

    private function approve(bool $approved): void
    {
        $this->putJson("/api/affiliates/{$this->affiliate->id}/domains/{$this->domain->id}/payments-approval", ['approved' => $approved])->assertOk();
    }

    /** @return list<string> the recipients of every email sent */
    private function recipients(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]['to'])->values()->all();
    }

    public function test_approving_emails_each_active_portal_login_once(): void
    {
        $this->user('owner@acme.test');
        $this->user('staff@acme.test');
        $this->user('gone@acme.test', active: false);

        $this->approve(true);

        $this->assertEqualsCanonicalizing(['owner@acme.test', 'staff@acme.test'], $this->recipients());
        Http::assertSent(fn ($r) => str_contains($r['subject'], 'shop.acme.test') && str_contains($r['body'], 'shop.acme.test'));
    }

    public function test_with_no_portal_login_the_affiliates_contact_email_is_used(): void
    {
        $this->approve(true);

        $this->assertSame(['contact@acme.test'], $this->recipients());
    }

    public function test_approving_again_and_revoking_send_nothing(): void
    {
        $this->user('owner@acme.test');
        $this->approve(true);
        Http::fake();

        $this->approve(true);
        $this->approve(false);

        Http::assertNothingSent();
    }

    public function test_a_failed_email_does_not_undo_the_approval(): void
    {
        $this->user('owner@acme.test');
        Http::fake(fn () => throw new ConnectionException('Plunk is down'));

        $this->approve(true);

        $this->assertNotNull($this->domain->fresh()->payments_approved_at);
    }
}
