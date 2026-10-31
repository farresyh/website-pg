<?php

namespace Tests\Feature\Partner;

use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Models\AffiliateDomain;
use App\Models\AffiliateUser;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerApiKey;
use App\Models\ResellerTier;
use App\Models\ResellerWebhook;
use App\Models\WhatsAppGroup;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\PaymentStatus;
use App\Services\Partner\PartnerReadinessService;
use App\Services\Partner\StepState;
use App\Services\Reseller\ResellerApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-133 decisions 13-15: a read-only readiness view over the gates the real code already has.
 * It never decides anything itself, so the invariant test below asks the real storefront.
 */
class PartnerReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    private PartnerReadinessService $readiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryAffiliate();
        $this->readiness = app(PartnerReadinessService::class);
    }

    private function affiliate(array $attributes = []): Affiliate
    {
        return Affiliate::query()->create(array_merge([
            'business_name' => 'Brand '.uniqid(), 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active',
        ], $attributes));
    }

    private function user(Affiliate|Reseller $owner, bool $withPassword = true): AffiliateUser
    {
        return AffiliateUser::query()->create([
            'owner_type' => ($owner instanceof Affiliate ? AccountOwnerType::Affiliate : AccountOwnerType::Reseller)->value,
            'owner_id' => $owner->id, 'name' => 'Owner', 'email' => uniqid().'@example.test',
            'password' => $withPassword ? bcrypt('password') : null, 'is_active' => true,
        ]);
    }

    private function domain(Affiliate $a, AffiliateDomainStatus $status, bool $approved = false, ?string $host = null): AffiliateDomain
    {
        return AffiliateDomain::query()->create([
            'affiliate_id' => $a->id, 'hostname' => $host ?? strtolower(uniqid()).'.shop.test', 'status' => $status,
            'is_primary' => false, 'provider_ref' => 'prj_'.uniqid(), 'payments_approved_at' => $approved ? now() : null,
        ]);
    }

    private function reseller(bool $withTier = true): Reseller
    {
        $tier = $withTier ? ResellerTier::query()->create(['name' => 'T'.uniqid(), 'markup_percent' => 5, 'is_active' => true, 'sort_order' => 1]) : null;

        $reseller = Reseller::query()->create(['business_name' => 'Res '.uniqid(), 'reseller_tier_id' => $tier?->id, 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }

    private function state(object $readiness, string $key): StepState
    {
        return $readiness->step($key)->state;
    }

    // ---- Affiliate ------------------------------------------------------------------------

    public function test_a_brand_new_affiliate_has_everything_to_do_and_is_not_ready(): void
    {
        $affiliate = $this->affiliate();
        $r = $this->readiness->forAffiliate($affiliate);

        $this->assertFalse($r->ready);
        $this->assertSame(StepState::Todo, $this->state($r, 'password'));
        foreach (['bank', 'domain', 'dns', 'payments_approval', 'branding', 'first_order'] as $key) {
            $this->assertSame(StepState::Todo, $this->state($r, $key), $key);
        }
        $this->assertSame(['password', 'bank', 'domain', 'dns', 'payments_approval', 'branding', 'markup', 'first_order'],
            array_map(fn ($s) => $s->key, $r->steps));
    }

    public function test_the_password_step_is_done_once_a_user_has_set_one(): void
    {
        $affiliate = $this->affiliate();
        $this->user($affiliate, withPassword: false);
        $this->assertSame(StepState::Todo, $this->state($this->readiness->forAffiliate($affiliate), 'password'));

        $this->user($affiliate);
        $this->assertSame(StepState::Done, $this->state($this->readiness->forAffiliate($affiliate), 'password'));
    }

    public function test_bank_uses_the_one_predicate_and_never_blocks_selling(): void
    {
        $affiliate = $this->affiliate();
        $this->assertFalse($this->readiness->forAffiliate($affiliate)->step('bank')->required);

        $affiliate->update(['bank_name' => 'Maybank', 'bank_account_no' => '1', 'bank_account_holder' => 'Acme']);
        $this->assertSame(StepState::Done, $this->state($this->readiness->forAffiliate($affiliate->fresh()), 'bank'));
    }

    public function test_an_owned_brand_has_no_bank_or_markup_step(): void
    {
        $keys = array_map(fn ($s) => $s->key, $this->readiness->forAffiliate($this->affiliate(['is_owned' => true, 'markup_pct' => 0]))->steps);

        $this->assertNotContains('bank', $keys);
        $this->assertNotContains('markup', $keys);
        $this->assertContains('payments_approval', $keys);
    }

    public function test_the_domain_steps_follow_the_domain_lifecycle(): void
    {
        $affiliate = $this->affiliate();

        $pending = $this->domain($affiliate, AffiliateDomainStatus::Pending);
        $r = $this->readiness->forAffiliate($affiliate);
        $this->assertSame(StepState::Done, $this->state($r, 'domain'));
        $this->assertSame(StepState::Todo, $this->state($r, 'dns'));
        $this->assertSame(StepState::Todo, $this->state($r, 'payments_approval'));

        $pending->update(['status' => AffiliateDomainStatus::Active]);
        $r = $this->readiness->forAffiliate($affiliate);
        $this->assertSame(StepState::Done, $this->state($r, 'dns'));
        $this->assertSame(StepState::WaitingOnUs, $this->state($r, 'payments_approval'));

        $pending->update(['payments_approved_at' => now()]);
        $this->assertSame(StepState::Done, $this->state($this->readiness->forAffiliate($affiliate), 'payments_approval'));
    }

    public function test_the_dns_step_carries_the_teardown_deadline_of_a_pending_domain(): void
    {
        config(['services.vercel.stuck_pending_days' => 14]);
        $affiliate = $this->affiliate();
        $domain = $this->domain($affiliate, AffiliateDomainStatus::Pending);
        $domain->forceFill(['created_at' => now()->subDays(3)])->save();

        $detail = $this->readiness->forAffiliate($affiliate)->step('dns')->detail;

        $this->assertSame($domain->created_at->copy()->addDays(14)->toIso8601String(), $detail);
    }

    public function test_branding_needs_a_logo_and_a_theme_other_than_the_platform_default(): void
    {
        $affiliate = $this->affiliate();
        $this->assertFalse($this->readiness->forAffiliate($affiliate)->step('branding')->required);

        AffiliateBranding::query()->create(['affiliate_id' => $affiliate->id, 'store_name' => 'X', 'theme_preset' => 'default', 'logo_path' => 'logo.png']);
        $this->assertSame(StepState::Todo, $this->state($this->readiness->forAffiliate($affiliate), 'branding'));

        AffiliateBranding::query()->where('affiliate_id', $affiliate->id)->update(['theme_preset' => 'ocean', 'logo_path' => null]);
        $this->assertSame(StepState::Todo, $this->state($this->readiness->forAffiliate($affiliate), 'branding'));

        AffiliateBranding::query()->where('affiliate_id', $affiliate->id)->update(['logo_path' => 'logo.png']);
        $this->assertSame(StepState::Done, $this->state($this->readiness->forAffiliate($affiliate), 'branding'));
    }

    public function test_markup_is_optional_and_never_complete(): void
    {
        $this->assertSame(StepState::Optional, $this->state($this->readiness->forAffiliate($this->affiliate()), 'markup'));
    }

    public function test_first_order_counts_only_paid_real_orders_sold_by_this_affiliate(): void
    {
        $affiliate = $this->affiliate();
        $other = $this->affiliate();

        Order::factory()->create(['affiliate_id' => $affiliate->id, 'payment_status' => PaymentStatus::Pending, 'is_test' => false]);
        Order::factory()->create(['affiliate_id' => $affiliate->id, 'payment_status' => PaymentStatus::Paid, 'is_test' => true]);
        Order::factory()->create(['affiliate_id' => $other->id, 'payment_status' => PaymentStatus::Paid, 'is_test' => false]);
        $this->assertSame(StepState::Todo, $this->state($this->readiness->forAffiliate($affiliate), 'first_order'));

        Order::factory()->create(['affiliate_id' => $affiliate->id, 'payment_status' => PaymentStatus::Paid, 'is_test' => false]);
        $this->assertSame(StepState::Done, $this->state($this->readiness->forAffiliate($affiliate), 'first_order'));
    }

    /**
     * The invariant that matters: "ready to sell" is true exactly when the real storefront would
     * take a payment on one of the affiliate's hosts (ADR-131's gate, read through the public
     * branding endpoint).
     */
    public function test_ready_to_sell_agrees_with_what_the_real_storefront_does(): void
    {
        $scenarios = [
            'no domain' => [fn ($a) => null],
            'pending' => [fn ($a) => $this->domain($a, AffiliateDomainStatus::Pending, true)],
            'failed' => [fn ($a) => $this->domain($a, AffiliateDomainStatus::Failed, true)],
            'suspended' => [fn ($a) => $this->domain($a, AffiliateDomainStatus::Suspended, true)],
            'active, not approved' => [fn ($a) => $this->domain($a, AffiliateDomainStatus::Active, false)],
            'active and approved' => [fn ($a) => $this->domain($a, AffiliateDomainStatus::Active, true)],
            'one pending and one approved' => [function ($a) {
                $this->domain($a, AffiliateDomainStatus::Pending, false);

                return $this->domain($a, AffiliateDomainStatus::Active, true);
            }],
        ];

        foreach ($scenarios as $name => [$build]) {
            foreach (['active', 'inactive'] as $status) {
                $affiliate = $this->affiliate(['status' => $status]);
                $this->user($affiliate);
                $build($affiliate);

                $storeTakesPayment = AffiliateDomain::query()->where('affiliate_id', $affiliate->id)->get()->contains(function ($d) {
                    $response = $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => $d->hostname]);

                    return $response->status() === 200 && $response->json('payments_enabled') === true;
                });

                $this->assertSame($storeTakesPayment, $this->readiness->forAffiliate($affiliate)->ready, "{$name} / {$status}");
            }
        }
    }

    // ---- Reseller -------------------------------------------------------------------------

    public function test_a_brand_new_reseller_with_a_tier(): void
    {
        $reseller = $this->reseller();
        $this->user($reseller);
        $r = $this->readiness->forReseller($reseller);

        $this->assertSame(['password', 'tier', 'add_balance', 'order_channel', 'first_order', 'webhook'], array_map(fn ($s) => $s->key, $r->steps));
        $this->assertSame(StepState::Done, $this->state($r, 'password'));
        $this->assertSame(StepState::Done, $this->state($r, 'tier'));
        $this->assertSame(StepState::Todo, $this->state($r, 'add_balance'));
        $this->assertSame(StepState::Todo, $this->state($r, 'order_channel'));
        $this->assertSame(StepState::Todo, $this->state($r, 'first_order'));
        $this->assertSame(StepState::Optional, $this->state($r, 'webhook'));
        $this->assertFalse($r->ready);
    }

    public function test_a_reseller_without_a_tier_is_never_ready(): void
    {
        $reseller = $this->reseller(withTier: false);
        $this->user($reseller);
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 5000, 'wallet_topup');
        app(ResellerApiKeyService::class)->issue($reseller, 'k');

        $r = $this->readiness->forReseller($reseller);

        $this->assertSame(StepState::Todo, $this->state($r, 'tier'));
        $this->assertFalse($r->ready);
    }

    public function test_add_balance_is_done_by_any_credit_including_an_admin_one(): void
    {
        $reseller = $this->reseller();
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 5000, 'adjustment');

        $this->assertSame(StepState::Done, $this->state($this->readiness->forReseller($reseller), 'add_balance'));
    }

    public function test_order_channel_is_an_active_api_key_or_a_linked_whatsapp_group(): void
    {
        $reseller = $this->reseller();
        $service = app(ResellerApiKeyService::class);

        ['key' => $key] = $this->issue($service, $reseller);
        $this->assertSame(StepState::Done, $this->state($this->readiness->forReseller($reseller), 'order_channel'));

        $service->revoke($key);
        $this->assertSame(StepState::Todo, $this->state($this->readiness->forReseller($reseller), 'order_channel'));

        WhatsAppGroup::query()->create(['owner_type' => 'reseller', 'owner_id' => $reseller->id, 'whatsapp_group_id' => 'g1@g.us', 'is_active' => false]);
        $this->assertSame(StepState::Todo, $this->state($this->readiness->forReseller($reseller), 'order_channel'));

        WhatsAppGroup::query()->where('owner_id', $reseller->id)->update(['is_active' => true]);
        $this->assertSame(StepState::Done, $this->state($this->readiness->forReseller($reseller), 'order_channel'));
    }

    public function test_first_order_and_webhook_for_a_reseller(): void
    {
        $reseller = $this->reseller();
        Order::factory()->create(['wallet_reseller_id' => $reseller->id, 'payment_status' => PaymentStatus::Pending, 'is_test' => false]);
        $this->assertSame(StepState::Todo, $this->state($this->readiness->forReseller($reseller), 'first_order'));

        Order::factory()->create(['wallet_reseller_id' => $reseller->id, 'payment_status' => PaymentStatus::Paid, 'is_test' => false]);
        ResellerWebhook::query()->create(['reseller_id' => $reseller->id, 'url' => 'https://example.test/hook', 'secret' => 's', 'is_active' => true]);
        $r = $this->readiness->forReseller($reseller);

        $this->assertSame(StepState::Done, $this->state($r, 'first_order'));
        $this->assertSame(StepState::Done, $this->state($r, 'webhook'));
    }

    public function test_a_reseller_is_ready_when_password_tier_balance_and_a_channel_are_done(): void
    {
        $reseller = $this->reseller();
        $this->user($reseller);
        $this->assertFalse($this->readiness->forReseller($reseller)->ready);

        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 5000, 'wallet_topup');
        $this->issue(app(ResellerApiKeyService::class), $reseller);

        $this->assertTrue($this->readiness->forReseller($reseller)->ready);

        $reseller->update(['is_active' => false]);
        $this->assertFalse($this->readiness->forReseller($reseller->fresh())->ready);
    }

    // ---- Batch ----------------------------------------------------------------------------

    public function test_a_partner_is_judged_alone_whoever_else_is_in_the_batch(): void
    {
        $a = $this->affiliate();
        $this->user($a);
        $this->domain($a, AffiliateDomainStatus::Active, true);
        $b = $this->affiliate();
        $r1 = $this->reseller();
        $r2 = $this->reseller(withTier: false);

        $together = $this->readiness->forAffiliates([$a, $b]);
        $this->assertEquals($this->readiness->forAffiliate($a), $together[$a->id]);
        $this->assertEquals($this->readiness->forAffiliate($b), $together[$b->id]);

        $resellers = $this->readiness->forResellers([$r1, $r2]);
        $this->assertEquals($this->readiness->forReseller($r1), $resellers[$r1->id]);
        $this->assertEquals($this->readiness->forReseller($r2), $resellers[$r2->id]);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_partners(): void
    {
        $count = function (int $n): int {
            $affiliates = collect(range(1, $n))->map(fn () => $this->affiliate())->each(fn ($a) => $this->domain($a, AffiliateDomainStatus::Active));
            $resellers = collect(range(1, $n))->map(fn () => $this->reseller());

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->readiness->forAffiliates($affiliates);
            $this->readiness->forResellers($resellers);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $small = $count(2);
        $large = $count(9);

        $this->assertSame($small, $large, 'readiness must not query per partner');
    }

    /** @return array{key: ResellerApiKey} */
    private function issue(ResellerApiKeyService $service, Reseller $reseller): array
    {
        $issued = $service->issue($reseller, 'k');

        return ['key' => $issued['key'] ?? $issued['api_key'] ?? $reseller->apiKeys()->latest('id')->firstOrFail()];
    }
}
