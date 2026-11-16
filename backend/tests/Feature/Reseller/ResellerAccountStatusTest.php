<?php

namespace Tests\Feature\Reseller;

use App\Models\AdminUser;
use App\Models\AffiliateUser;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Models\ResellerStatusChange;
use App\Models\WalletTopupAttempt;
use App\Services\Order\PlacedVia;
use App\Services\Reseller\Bot\ResellerBotService;
use App\Services\Reseller\ResellerAccountStatus;
use App\Services\Reseller\ResellerApiKeyService;
use App\Services\Reseller\ResellerDeactivationReason;
use App\Services\Reseller\ResellerInactiveException;
use App\Services\Reseller\ResellerOrderPlacementRequest;
use App\Services\Reseller\ResellerOrderPlacementService;
use App\Services\Reseller\ResellerWalletTopupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ResellerBotFixtures;
use Tests\TestCase;

/**
 * ADR-142 decisions 6, 15–18: one seam writes the status with a reason and a
 * history, and every channel refuses a deactivated reseller with the same
 * denial (portal login, REST API, bot, top-up start, order placement).
 */
class ResellerAccountStatusTest extends TestCase
{
    use RefreshDatabase;
    use ResellerBotFixtures;

    private function seam(): ResellerAccountStatus
    {
        return app(ResellerAccountStatus::class);
    }

    private function portalUser(Reseller $reseller): AffiliateUser
    {
        return AffiliateUser::query()->create([
            'owner_type' => 'reseller', 'owner_id' => $reseller->id, 'name' => 'Staff',
            'email' => 'staff@acme.test', 'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);
    }

    private function dormant(Reseller $reseller): void
    {
        PlatformSettings::current()->update(['reseller_dormancy_days' => 7, 'reseller_support_whatsapp' => '+60123456789']);
        $this->seam()->deactivate($reseller, ResellerDeactivationReason::Dormant, null);
    }

    public function test_deactivating_records_the_reason_and_history_and_ends_portal_sessions(): void
    {
        $admin = AdminUser::factory()->create();
        $reseller = $this->makeLinkedReseller();
        $user = $this->portalUser($reseller);
        $user->createToken('portal');

        $this->assertTrue($this->seam()->deactivate($reseller, ResellerDeactivationReason::Manual, $admin->id));
        $this->assertFalse($this->seam()->deactivate($reseller, ResellerDeactivationReason::Manual, $admin->id), 'the same reason twice changes nothing');

        $fresh = $reseller->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame(ResellerDeactivationReason::Manual, $fresh->deactivation_reason);
        $this->assertNotNull($fresh->deactivated_at);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, ResellerStatusChange::query()->where('admin_user_id', $admin->id)->where('is_active', false)->count());
    }

    public function test_reactivating_clears_the_reason_and_is_recorded(): void
    {
        $admin = AdminUser::factory()->create();
        $reseller = $this->makeLinkedReseller();
        $this->dormant($reseller);

        $this->assertTrue($this->seam()->reactivate($reseller, $admin->id));

        $fresh = $reseller->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->deactivation_reason);
        $this->assertSame([false, true], ResellerStatusChange::query()->orderBy('id')->pluck('is_active')->all());
    }

    /** The admin toggle goes through the seam (reason `manual`, who). */
    public function test_the_admin_toggle_records_who_and_why(): void
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        $this->actingAs($admin, 'sanctum');
        $reseller = $this->makeLinkedReseller();

        $this->patchJson("/api/resellers/{$reseller->id}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('deactivation_reason', 'manual');
        $this->getJson("/api/resellers/{$reseller->id}")->assertOk()
            ->assertJsonPath('status_history.0.reason', 'manual')
            ->assertJsonPath('status_history.0.admin_name', $admin->name);
    }

    public function test_a_deactivated_reseller_is_refused_at_login_with_the_reason(): void
    {
        $reseller = $this->makeLinkedReseller();
        $this->portalUser($reseller);
        $this->dormant($reseller);

        $this->postJson('/api/affiliate/login', ['email' => 'staff@acme.test', 'password' => 'secret-password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'This account was deactivated after 7 days with no transaction. WhatsApp us at +60123456789 to reactivate it.');
    }

    /** The bug the stress test found: `.topupbaki` ran for a deactivated reseller. */
    public function test_the_bot_refuses_every_command_including_topupbaki(): void
    {
        $this->captureReplies();
        $reseller = $this->makeLinkedReseller();
        $this->dormant($reseller);

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 50', 'msg-1', now()->getTimestamp());
        app(ResellerBotService::class)->handle(self::GROUP_ID, '.info', 'msg-2', now()->getTimestamp());

        $this->assertSame(0, WalletTopupAttempt::query()->count());
        $this->assertCount(2, $this->replies());
        $this->assertStringContainsString('tiada transaksi selama 7 hari', $this->replies()[0]);
    }

    public function test_a_top_up_cannot_start_on_a_deactivated_account(): void
    {
        $this->activeFpx();
        $reseller = $this->makeLinkedReseller();
        $this->seam()->deactivate($reseller, ResellerDeactivationReason::Manual, null);

        $this->expectException(ResellerInactiveException::class);
        app(ResellerWalletTopupService::class)->initiate($reseller->fresh(), 5000, 'fpx', []);
    }

    /** Decision 18: the flag is re-read under the wallet lock, so a stale "active" copy cannot order. */
    public function test_an_order_from_a_stale_active_copy_is_refused_under_the_lock(): void
    {
        $package = $this->makePackage(requiresServerId: false);
        $reseller = $this->makeLinkedReseller();
        $stale = Reseller::query()->find($reseller->id);
        $this->seam()->deactivate($reseller, ResellerDeactivationReason::Manual, null);
        $this->assertTrue($stale->is_active);

        try {
            app(ResellerOrderPlacementService::class)->placeOrder($stale, new ResellerOrderPlacementRequest(
                playerId: '12345', serverId: null, costPriceSen: $package->cost_price,
                standardSellingPriceSen: $package->standard_selling_price, idempotencyKey: 'stale-1',
                placedVia: PlacedVia::ResellerApi, supplierProductRef: 'A', gameId: $package->game_id,
                packageId: $package->id, supplierId: $package->supplier_id,
            ));
            $this->fail('A stale active copy placed an order.');
        } catch (ResellerInactiveException) {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_the_api_answers_with_the_reasoned_message(): void
    {
        $reseller = $this->makeLinkedReseller();
        ['plainText' => $key] = app(ResellerApiKeyService::class)->issue($reseller, 'test');
        $this->dormant($reseller);

        $this->getJson('/api/reseller/v1/catalog', ['Authorization' => "Bearer {$key}"])
            ->assertForbidden()
            ->assertJsonPath('error', 'RESELLER_INACTIVE')
            ->assertJsonPath('message', 'This account was deactivated after 7 days with no transaction. WhatsApp us at +60123456789 to reactivate it.');
    }

    public function test_closing_an_already_deactivated_account_records_closed(): void
    {
        $admin = AdminUser::factory()->create();
        $reseller = $this->makeLinkedReseller(walletBalance: 0);
        $this->dormant($reseller);

        $this->seam()->deactivate($reseller, ResellerDeactivationReason::Closed, $admin->id);

        $this->assertSame(ResellerDeactivationReason::Closed, $reseller->fresh()->deactivation_reason);
        $this->assertSame(['dormant', 'closed'], ResellerStatusChange::query()->orderBy('id')->get()->map(fn ($c) => $c->reason->value)->all());
    }

    public function test_the_settings_switch_stores_when_it_went_on_and_needs_the_lead_below_the_period(): void
    {
        $admin = AdminUser::factory()->superAdmin()->create();
        $this->actingAsSteppedUp($admin);
        $body = ['reseller_dormancy_enabled' => true, 'reseller_dormancy_days' => 7, 'reseller_dormancy_warning_days' => 3, 'reseller_support_whatsapp' => '+60 12-345 6789'];

        $this->putJson('/api/settings/reseller-dormancy-policy', $body)->assertOk();
        $first = PlatformSettings::current()->reseller_dormancy_enabled_at;
        $this->assertNotNull($first);

        $this->travel(2)->days();
        $this->actingAsSteppedUp($admin);
        $this->putJson('/api/settings/reseller-dormancy-policy', ['reseller_dormancy_days' => 10] + $body)->assertOk();
        $this->assertTrue(PlatformSettings::current()->reseller_dormancy_enabled_at->equalTo($first), 'saving again keeps the start');

        $this->putJson('/api/settings/reseller-dormancy-policy', ['reseller_dormancy_enabled' => false] + $body)->assertOk();
        $this->assertNull(PlatformSettings::current()->reseller_dormancy_enabled_at);

        $this->putJson('/api/settings/reseller-dormancy-policy', ['reseller_dormancy_warning_days' => 7] + $body)
            ->assertUnprocessable()->assertJsonValidationErrors('reseller_dormancy_warning_days');
    }
}
