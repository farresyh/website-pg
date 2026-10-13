<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\CustomerNotification;
use App\Models\PlatformSettings;
use App\Models\Voucher;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-024 2026-10-13 addendum (§16 item 88): a typed voucher expiry date
 * means "valid through the end of that Malaysia day" (23:59:59 MYT), not
 * midnight UTC (08:00 MYT that day).
 */
class VoucherExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelToMyt('2026-10-13 10:00:00');
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    private function travelToMyt(string $kualaLumpurTime): void
    {
        $this->travelTo(Carbon::parse($kualaLumpurTime, 'Asia/Kuala_Lumpur'));
    }

    private function create(?string $expiresAt, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/vouchers', array_merge([
            'customer_email' => 'customer@example.com',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'amount' => 1_000,
            'reason' => 'Goodwill credit',
            'idempotency_key' => (string) Str::uuid(),
            'expires_at' => $expiresAt,
        ], $overrides));
    }

    private function storedExpiry(TestResponse $response): string
    {
        return Voucher::query()->where('code', $response->assertCreated()->json('code'))->sole()->getRawOriginal('expires_at');
    }

    public function test_a_typed_date_is_stored_as_the_end_of_that_malaysia_day(): void
    {
        $this->assertSame('2026-11-01 15:59:59', $this->storedExpiry($this->create('2026-11-01')));
    }

    public function test_the_voucher_is_usable_all_of_its_last_malaysia_day(): void
    {
        $code = $this->create('2026-11-01')->json('code');

        $this->travelToMyt('2026-11-01 23:00:00');
        $this->assertFalse(Voucher::query()->where('code', $code)->sole()->isExpired());

        $this->travelToMyt('2026-11-02 00:00:00');
        $this->assertTrue(Voucher::query()->where('code', $code)->sole()->isExpired());
    }

    public function test_a_merge_uses_the_same_end_of_day_rule(): void
    {
        $a = $this->create(null)->json('id');
        $b = $this->create(null)->json('id');

        $response = $this->postJson('/api/vouchers/merge', [
            'voucher_ids' => [$a, $b],
            'reason' => 'Combine two codes',
            'expires_at' => '2026-11-01',
        ]);

        $this->assertSame('2026-11-01 15:59:59', $this->storedExpiry($response));
    }

    /** Laravel's `today` is UTC: at 02:00 MYT it is still yesterday there. */
    public function test_today_in_malaysia_is_the_earliest_date_even_before_08_00(): void
    {
        $this->travelToMyt('2026-10-14 02:00:00');

        $this->create('2026-10-13')->assertJsonValidationErrors('expires_at');
        $this->assertSame('2026-10-14 15:59:59', $this->storedExpiry($this->create('2026-10-14')));
    }

    public function test_only_a_plain_date_is_accepted(): void
    {
        $this->create('2026-11-01 15:00')->assertJsonValidationErrors('expires_at');
        $this->create('next friday')->assertJsonValidationErrors('expires_at');
    }

    public function test_the_whatsapp_message_shows_the_typed_date(): void
    {
        config([
            'services.openwa.base_url' => 'http://openwa.test',
            'services.openwa.cs_session_id' => 'cs-session',
            'services.openwa.cs_api_key' => 'cs-key',
        ]);
        PlatformSettings::current()->update(['whatsapp_notifications_enabled' => true]);

        $this->create('2026-11-01', ['customer_phone' => '012-345 6789'])->assertCreated();

        $this->assertStringContainsString('Valid until: 1 Nov 2026', CustomerNotification::query()->sole()->message);
    }

    public function test_a_voucher_typed_for_the_last_day_of_a_month_is_that_months_breakage(): void
    {
        $this->create('2026-10-31')->assertCreated();
        $this->create('2026-11-01')->assertCreated();

        $this->travelToMyt('2026-12-01 10:00:00');
        $summary = app(MonthlyAccountingSummaryService::class);

        $this->assertSame(1_000, $summary->forPeriod(2026, 10)['voucher_breakage_sen']);
        $this->assertSame(1_000, $summary->forPeriod(2026, 11)['voucher_breakage_sen']);
    }
}
