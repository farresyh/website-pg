<?php

namespace Tests\Feature\Rebate;

use App\Models\AdminUser;
use App\Services\Accounting\TransactionRegisterService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Rebate\RebateClaimService;
use App\Services\Reseller\ResellerClosureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-139 (2026-11-09 review): the Transaction Register lists a rebate claim
 * and a closure forfeit, so the year-end record holds every wallet movement,
 * as it already does for a wallet refund.
 */
class RebateRegisterTest extends TestCase
{
    use RebateFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        AdminUser::factory()->create(['is_active' => true]);
        Carbon::setTestNow(Carbon::parse('2026-12-15 02:00:00', 'UTC'));
        $this->seedRebateWorld();
    }

    public function test_a_rebate_claim_and_its_closure_forfeit_each_have_a_register_row(): void
    {
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $this->reseller->id, 5000, 'wallet_topup');
        $this->delivered(60000, 1740)->update(['paid_at' => '2026-12-10 03:59:00']); // a real paid order; the register reads paid_at
        app(RebateClaimService::class)->claim($this->reseller, $this->user, 2026, 12, 1, acceptTerms: true);

        $claim = $this->rows('reseller_rebate');
        $this->assertCount(1, $claim);
        $this->assertSame(100, $claim[0]['net_sen']);
        $this->assertSame('Reseller rebate claimed — Acme, RM500.00 step (December 2026)', $claim[0]['description']);

        app(ResellerClosureService::class)->close($this->reseller, AdminUser::query()->first()->id, '2026-12-15', 'BANK-1');

        $forfeit = $this->rows('reseller_rebate_forfeit');
        $this->assertCount(1, $forfeit);
        $this->assertSame(-100, $forfeit[0]['net_sen']);
        $this->assertSame('Unspent rebate forfeited at account closure — Acme', $forfeit[0]['description']);
        $this->assertSame(-5000, $this->rows('reseller_wallet_closure')[0]['net_sen'], 'the cash refund row is the rest of the balance');
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $type): array
    {
        return app(TransactionRegisterService::class)->rows(now()->subMonth(), now()->addDay(), $type);
    }
}
