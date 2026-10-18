<?php

namespace Tests\Feature\Console;

use App\Models\OrderCredential;
use App\Models\PlatformSettings;
use App\Models\Voucher;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminOrderControllerFixtures;
use Tests\Support\OrderCredentialFixtures;
use Tests\TestCase;

/**
 * ADR-124 decision 7 as revised by its 2026-10-18 addendum: the one
 * invariant test of every wipe rule, and of the logins it must keep.
 * Defaults: G = 24 h after closing, H = 24 h unpaid, D = 14 days.
 */
class WipeOrderCredentialsCommandTest extends TestCase
{
    use AdminOrderControllerFixtures;
    use OrderCredentialFixtures;
    use RefreshDatabase;

    private function sweep(): void
    {
        $this->artisan('app:wipe-order-credentials')->assertSuccessful();
    }

    private function assertWiped(OrderCredential $credential): void
    {
        $fresh = $credential->fresh();
        $this->assertNotNull($fresh->purged_at);
        $this->assertNull($fresh->getRawOriginal('secrets'));
        // Consent and the non-secret fields are kept (decision 8).
        $this->assertNotNull($fresh->consented_at);
        $this->assertSame($credential->consent_text, $fresh->consent_text);
        $this->assertSame($credential->fields, $fresh->fields);
    }

    private function assertKept(OrderCredential $credential): void
    {
        $fresh = $credential->fresh();
        $this->assertNull($fresh->purged_at);
        $this->assertSame(['password' => 'hunter2-secret'], $fresh->secrets);
    }

    private function paid(DeliveryStatus $delivery): array
    {
        return ['payment_status' => PaymentStatus::Paid->value, 'paid_at' => now(), 'delivery_status' => $delivery->value];
    }

    public function test_a_delivered_order_is_stamped_closed_then_wiped_after_the_grace(): void
    {
        $credential = $this->credentialFor($this->order($this->paid(DeliveryStatus::Delivered)));

        $this->sweep();
        $this->assertNotNull($credential->fresh()->closed_at);
        $this->assertKept($credential);

        $this->travel(23)->hours();
        $this->sweep();
        $this->assertKept($credential);

        $this->travel(2)->hours();
        $this->sweep();
        $this->assertWiped($credential);
    }

    public function test_a_compensated_failed_order_is_closed_and_wiped_after_the_grace(): void
    {
        $order = $this->order($this->paid(DeliveryStatus::Failed));
        $credential = $this->credentialFor($order);
        Voucher::query()->create(['order_id' => $order->id, 'affiliate_id' => $order->affiliate_id, 'code' => 'VC-CRED', 'customer_email' => 'a@example.com', 'amount' => 1000, 'remaining' => 1000, 'status' => 'active', 'reason' => 'failed']);

        $this->sweep();
        $this->assertNotNull($credential->fresh()->closed_at);

        $this->travel(25)->hours();
        $this->sweep();
        $this->assertWiped($credential);
    }

    /** The 2026-10-18 addendum, decision 2: a Paid can still land after a Failed payment. */
    public function test_an_unpaid_or_payment_failed_order_is_wiped_only_after_h_hours(): void
    {
        $pending = $this->credentialFor($this->order());
        $failed = $this->credentialFor($this->order(['payment_status' => PaymentStatus::Failed->value]));

        $this->travel(23)->hours();
        $this->sweep();
        $this->assertKept($pending);
        $this->assertKept($failed);

        $this->travel(2)->hours();
        $this->sweep();
        $this->assertWiped($pending);
        $this->assertWiped($failed);
    }

    public function test_an_open_failed_or_needs_review_order_keeps_its_login_inside_d_days(): void
    {
        $failed = $this->credentialFor($this->order($this->paid(DeliveryStatus::Failed)));
        $review = $this->credentialFor($this->order($this->paid(DeliveryStatus::NeedsReview)));

        $this->travel(13)->days();
        $this->sweep();
        $this->assertKept($failed);
        $this->assertKept($review);
        $this->assertNull($failed->fresh()->closed_at);
    }

    /** The 2026-10-18 addendum, decision 4: D counts from the last time the login was stored or replaced. */
    public function test_the_d_ceiling_counts_from_secrets_set_at_not_the_order(): void
    {
        $order = $this->order($this->paid(DeliveryStatus::NeedsReview));
        $credential = $this->credentialFor($order, ['secrets_set_at' => now()]);

        $this->travel(15)->days();
        $this->sweep();
        $this->assertWiped($credential);

        // A super_admin replaced it on day 15: the order is still open.
        OrderCredential::query()->whereKey($credential->id)->update(['purged_at' => null]);
        $credential->fresh()->forceFill(['secrets' => ['password' => 'hunter2-secret'], 'secrets_set_at' => now()])->save();

        $this->travel(1)->hours();
        $this->sweep();
        $this->assertKept($credential);
    }

    public function test_the_policy_hours_come_from_platform_settings(): void
    {
        PlatformSettings::current()->update(['credential_close_grace_hours' => 2]);
        $credential = $this->credentialFor($this->order($this->paid(DeliveryStatus::Delivered)));

        $this->sweep();
        $this->travel(3)->hours();
        $this->sweep();

        $this->assertWiped($credential);
    }

    public function test_a_wiped_row_is_left_alone_by_the_next_sweep(): void
    {
        $credential = $this->credentialFor($this->order(['payment_status' => PaymentStatus::Failed->value]));
        $this->travel(25)->hours();
        $this->sweep();
        $purgedAt = $credential->fresh()->purged_at;

        $this->travel(1)->hours();
        $this->sweep();

        $this->assertEquals($purgedAt, $credential->fresh()->purged_at);
    }
}
