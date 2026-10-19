<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderCredential;
use App\Models\PlatformSettings;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdminOrderControllerFixtures;
use Tests\Support\OrderCredentialFixtures;
use Tests\TestCase;

/** ADR-124 decisions 1, 3, 5, 6 (+ the 2026-10-18 addendum): who can see or change a customer's login, and what is left behind. */
class OrderCredentialControllerTest extends TestCase
{
    use AdminOrderControllerFixtures;
    use OrderCredentialFixtures;
    use RefreshDatabase;

    private function superAdmin(): AdminUser
    {
        return $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());
    }

    private function openOrder(): Order
    {
        return $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
    }

    public function test_a_super_admin_reveals_one_field_and_it_is_audited(): void
    {
        $admin = $this->superAdmin();
        $order = $this->openOrder();
        $this->credentialFor($order);

        $this->postJson("/api/orders/{$order->id}/credentials/reveal", ['field' => 'password'])
            ->assertOk()
            ->assertExactJson(['field' => 'password', 'value' => 'hunter2-secret'])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseHas('credential_reveals', [
            'order_id' => $order->id, 'actor_type' => 'admin', 'actor_id' => $admin->id,
            'action' => 'reveal', 'field_key' => 'password',
        ]);
    }

    public function test_a_plain_admin_cannot_reveal(): void
    {
        $this->actingAsSteppedUp(AdminUser::factory()->create(['role' => 'admin']));
        $order = $this->openOrder();
        $this->credentialFor($order);

        $this->postJson("/api/orders/{$order->id}/credentials/reveal", ['field' => 'password'])->assertForbidden();
        $this->assertDatabaseCount('credential_reveals', 0);
    }

    public function test_reveal_needs_step_up(): void
    {
        $admin = AdminUser::factory()->superAdmin()->create(['mfa_required' => true, 'mfa_enabled' => true]);
        Sanctum::actingAs($admin);
        $order = $this->openOrder();
        $this->credentialFor($order);

        $this->withHeader('Referer', 'http://localhost:3000/')
            ->postJson("/api/orders/{$order->id}/credentials/reveal", ['field' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('code', 'mfa_step_up_required');
    }

    public function test_a_non_secret_field_a_wiped_login_and_a_sandbox_order_reveal_nothing(): void
    {
        $this->superAdmin();
        $order = $this->openOrder();
        $credential = $this->credentialFor($order);

        $this->postJson("/api/orders/{$order->id}/credentials/reveal", ['field' => 'username'])->assertUnprocessable();

        OrderCredential::query()->whereKey($credential->id)->update(['secrets' => null, 'purged_at' => now()]);
        $this->postJson("/api/orders/{$order->id}/credentials/reveal", ['field' => 'password'])->assertStatus(410);

        $sandbox = $this->order(['is_test' => true]);
        $this->credentialFor($sandbox);
        $this->postJson("/api/orders/{$sandbox->id}/credentials/reveal", ['field' => 'password'])->assertNotFound();

        $this->assertDatabaseCount('credential_reveals', 0);
    }

    public function test_reveals_are_limited_per_admin_by_the_policy(): void
    {
        PlatformSettings::current()->update(['credential_reveal_limit_per_hour' => 2]);
        $this->superAdmin();
        $order = $this->openOrder();
        $this->credentialFor($order);

        $this->postJson("/api/orders/{$order->id}/credentials/reveal", ['field' => 'password'])->assertOk();
        $this->postJson("/api/orders/{$order->id}/credentials/reveal", ['field' => 'password'])->assertOk();
        $this->postJson("/api/orders/{$order->id}/credentials/reveal", ['field' => 'password'])->assertStatus(429);
        $this->assertDatabaseCount('credential_reveals', 2);
    }

    public function test_replace_on_an_open_order_rearms_a_wiped_login_and_is_audited(): void
    {
        $this->travelTo(now()->subDays(20));
        $order = $this->openOrder();
        $credential = $this->credentialFor($order);
        $this->travelBack();
        OrderCredential::query()->whereKey($credential->id)->update(['secrets' => null, 'purged_at' => now()]);
        $this->superAdmin();

        $this->putJson("/api/orders/{$order->id}/credentials", ['field' => 'password', 'value' => 'new-pass-123'])
            ->assertOk()
            ->assertJsonPath('purged_at', null)
            ->assertJsonMissing(['new-pass-123']);

        $fresh = $credential->fresh();
        $this->assertSame(['password' => 'new-pass-123'], $fresh->secrets);
        $this->assertTrue($fresh->secrets_set_at->isToday());
        $this->assertDatabaseHas('credential_reveals', ['order_id' => $order->id, 'action' => 'replace', 'field_key' => 'password']);

        // D restarts from the replace, so the next sweep keeps it.
        $this->artisan('app:wipe-order-credentials')->assertSuccessful();
        $this->assertNull($credential->fresh()->purged_at);
    }

    public function test_replace_is_refused_on_a_closed_order(): void
    {
        $this->superAdmin();
        $order = $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);
        $credential = $this->credentialFor($order);

        $this->putJson("/api/orders/{$order->id}/credentials", ['field' => 'password', 'value' => 'new-pass-123'])->assertStatus(409);
        $this->assertSame(['password' => 'hunter2-secret'], $credential->fresh()->secrets);
        $this->assertDatabaseCount('credential_reveals', 0);
    }

    public function test_order_detail_shows_the_login_without_its_secret(): void
    {
        $this->actingAsAdmin();
        $order = $this->openOrder();
        $this->credentialFor($order);

        $response = $this->getJson("/api/orders/{$order->id}")->assertOk();

        $response->assertJsonPath('customer_login.fields.0.value', 'player_one')
            ->assertJsonPath('customer_login.secret_fields.0.key', 'password');
        $this->assertStringNotContainsString('hunter2-secret', $response->getContent());
        $this->assertStringNotContainsString('hunter2-secret', json_encode($order->fresh()->toArray()));
    }

    /** Decision 1: nothing on Order may reach the login; decision 3: no dump carries it. */
    public function test_order_has_no_credentials_relation_and_dumps_leave_the_table_out(): void
    {
        $this->assertFalse(method_exists(Order::class, 'credentials'));
        $this->assertFalse(method_exists(Order::class, 'credential'));
        $this->assertFalse(method_exists(Order::class, 'orderCredential'));

        $this->assertContains('order_credentials', config('database.connections.mysql.dump.exclude_tables'));
        $this->assertContains('order_credentials', config('database.connections.sqlite.dump.exclude_tables'));
    }

    public function test_the_login_policy_is_set_from_settings_behind_step_up(): void
    {
        $this->superAdmin();
        $policy = [
            'credential_close_grace_hours' => 12, 'credential_unpaid_hours' => 6, 'credential_max_days' => 7,
            'credential_reveal_limit_per_hour' => 10, 'credential_consent_text' => 'I allow this store to use my login for this order only.',
        ];

        $this->putJson('/api/settings/customer-login-policy', $policy)->assertOk()->assertJsonPath('credential_max_days', 7);
        $this->assertSame(6, PlatformSettings::current()->credential_unpaid_hours);

        $this->putJson('/api/settings/customer-login-policy', ['credential_unpaid_hours' => 0] + $policy)
            ->assertUnprocessable()->assertJsonValidationErrors('credential_unpaid_hours');

        // The unguarded platform form can't change the policy.
        $this->putJson('/api/settings/platform', ['maintenance_mode' => false, 'vip_spend_threshold_sen' => 1, 'credential_max_days' => 90])->assertOk();
        $this->assertSame(7, PlatformSettings::current()->credential_max_days);
    }
}
