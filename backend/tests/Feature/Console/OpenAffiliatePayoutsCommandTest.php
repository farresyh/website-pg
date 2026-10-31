<?php

namespace Tests\Feature\Console;

use App\Models\Affiliate;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenAffiliatePayoutsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_opens_tickets_and_a_rerun_opens_none(): void
    {
        $a = Affiliate::query()->create([
            'business_name' => 'Acme', 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active',
            'bank_name' => 'Maybank', 'bank_account_no' => '1', 'bank_account_holder' => 'Acme',
        ]);
        $this->travelTo($this->midLastMonth());
        app(LedgerService::class)->credit('affiliate', $a->id, 25000, 'order_profit', 'order', 1);
        $this->travelBack();

        $this->artisan('app:open-affiliate-payouts')->expectsOutput('Affiliate payouts: 1 opened, 0 failed.')->assertSuccessful();
        $this->artisan('app:open-affiliate-payouts')->expectsOutput('Affiliate payouts: 0 opened, 0 failed.')->assertSuccessful();

        $this->assertSame(1, Withdrawal::query()->count());
    }
}
