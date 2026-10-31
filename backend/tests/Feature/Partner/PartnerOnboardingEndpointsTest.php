<?php

namespace Tests\Feature\Partner;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\AffiliateUser;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\WhatsAppGroup;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-133 decisions 14, 15 and 18: the checklist the portals read and the readiness column the
 * admin lists carry. One service behind all of them (PartnerReadinessService).
 */
class PartnerOnboardingEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryAffiliate();
    }

    private function affiliate(array $attributes = []): Affiliate
    {
        return Affiliate::query()->create(array_merge([
            'business_name' => 'Brand '.uniqid(), 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active',
        ], $attributes));
    }

    private function reseller(bool $withTier = true): Reseller
    {
        $tier = $withTier ? ResellerTier::query()->create(['name' => 'T'.uniqid(), 'markup_percent' => 5, 'is_active' => true, 'sort_order' => 1]) : null;
        $reseller = Reseller::query()->create(['business_name' => 'Res '.uniqid(), 'reseller_tier_id' => $tier?->id, 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }

    private function actAs(Affiliate|Reseller $owner): AffiliateUser
    {
        $user = AffiliateUser::query()->create([
            'owner_type' => ($owner instanceof Affiliate ? AccountOwnerType::Affiliate : AccountOwnerType::Reseller)->value,
            'owner_id' => $owner->id, 'name' => 'Owner', 'email' => uniqid().'@example.test', 'password' => bcrypt('password'), 'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*'], 'affiliate');

        return $user;
    }

    /** @return array<string, string> key => state */
    private function states(array $json): array
    {
        return collect($json['steps'])->pluck('state', 'key')->all();
    }

    // ---- Portals --------------------------------------------------------------------------

    public function test_an_affiliate_reads_its_own_checklist(): void
    {
        $affiliate = $this->affiliate();
        AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id, 'hostname' => 'shop.acme.test', 'status' => AffiliateDomainStatus::Active, 'is_primary' => true,
        ]);
        $this->actAs($affiliate);

        $json = $this->getJson('/api/affiliate/onboarding')->assertOk()->json();

        $this->assertFalse($json['ready']);
        $this->assertSame('done', $this->states($json)['password']);
        $this->assertSame('done', $this->states($json)['dns']);
        $this->assertSame('waiting_on_us', $this->states($json)['payments_approval']);
        $this->assertSame(['key', 'state', 'required', 'detail'], array_keys($json['steps'][0]));
    }

    public function test_an_affiliate_never_sees_another_affiliates_state(): void
    {
        $mine = $this->affiliate();
        $other = $this->affiliate(['bank_name' => 'Maybank', 'bank_account_no' => '1', 'bank_account_holder' => 'Other']);
        $this->actAs($mine);

        $this->assertSame('todo', $this->states($this->getJson('/api/affiliate/onboarding')->json())['bank']);
        $this->assertTrue($other->hasBankDetails());
    }

    public function test_a_reseller_reads_its_checklist_and_which_channels_are_set_up(): void
    {
        $reseller = $this->reseller();
        WhatsAppGroup::query()->create(['owner_type' => 'reseller', 'owner_id' => $reseller->id, 'whatsapp_group_id' => 'g1@g.us', 'is_active' => true]);
        $this->actAs($reseller);

        $json = $this->getJson('/api/reseller-portal/onboarding')->assertOk()->json();

        $this->assertSame(['password', 'tier', 'add_balance', 'order_channel', 'first_order', 'webhook'], array_column($json['steps'], 'key'));
        $channel = collect($json['steps'])->firstWhere('key', 'order_channel');
        $this->assertSame('done', $channel['state']);
        $this->assertSame('whatsapp_group', $channel['detail']);
    }

    public function test_each_portal_endpoint_refuses_the_other_account_type(): void
    {
        $this->actAs($this->reseller());
        $this->getJson('/api/affiliate/onboarding')->assertForbidden();

        $this->actAs($this->affiliate());
        $this->getJson('/api/reseller-portal/onboarding')->assertForbidden();
    }

    public function test_the_checklist_needs_a_login(): void
    {
        $this->getJson('/api/affiliate/onboarding')->assertUnauthorized();
        $this->getJson('/api/reseller-portal/onboarding')->assertUnauthorized();
    }

    // ---- Admin lists ----------------------------------------------------------------------

    public function test_the_admin_affiliate_list_carries_each_rows_readiness(): void
    {
        $waiting = $this->affiliate(['business_name' => 'Waiting Co']);
        AffiliateDomain::query()->create(['affiliate_id' => $waiting->id, 'hostname' => 'w.test', 'status' => AffiliateDomainStatus::Active, 'is_primary' => true]);
        $fresh = $this->affiliate(['business_name' => 'Fresh Co']);
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());

        $rows = collect($this->getJson('/api/affiliates')->assertOk()->json('affiliates'))->keyBy('business_name');

        $this->assertSame(['domain', 'dns', 'payments_approval'], $rows['Fresh Co']['readiness']['needs']);
        $this->assertFalse($rows['Fresh Co']['readiness']['waiting_on_us']);
        $this->assertSame(['payments_approval'], $rows['Waiting Co']['readiness']['needs']);
        $this->assertTrue($rows['Waiting Co']['readiness']['waiting_on_us']);
        $this->assertNull($rows['Platform Owner']['readiness'] ?? null, 'the platform brand is not a partner');
        $this->assertNotNull($fresh);
    }

    public function test_the_admin_reseller_list_carries_each_rows_readiness(): void
    {
        $this->reseller(withTier: false);
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());

        $row = $this->getJson('/api/resellers')->assertOk()->json('resellers.0');

        $this->assertFalse($row['readiness']['ready']);
        $this->assertSame(['tier', 'add_balance', 'order_channel'], $row['readiness']['needs']);
    }

    public function test_the_admin_lists_do_not_query_per_row(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
        $count = function (int $n): int {
            foreach (range(1, $n) as $_) {
                $this->affiliate();
                $this->reseller();
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/affiliates')->assertOk();
            $this->getJson('/api/resellers')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $count(1); // the first measured request pays for one-off work (settings cache), so it is not compared
        $small = $count(2);
        $large = $count(6);

        $this->assertLessThanOrEqual($small, $large, 'the lists must not query per row');
    }
}
