<?php

namespace Tests\Concurrency;

use App\Models\AffiliateUser;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerRebateLadder;
use App\Models\ResellerTier;
use App\Models\ResellerWalletTierChange;
use App\Services\Auth\AccountOwnerType;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-139 decision 11: two simultaneous claims of the same step credit once.
 * The claim serializes on the reseller's ledger_accounts row; the
 * wallet_rebate dedupe key is the backstop.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class RebateClaimConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_claims_of_a_step_is_credited(): void
    {
        $year = (int) now('Asia/Kuala_Lumpur')->format('Y');
        $month = (int) now('Asia/Kuala_Lumpur')->format('n');
        $first = now('Asia/Kuala_Lumpur')->startOfMonth()->toDateString();

        $tier = ResellerTier::query()->create(['name' => 'Tier SS', 'markup_percent' => 3, 'is_active' => true, 'sort_order' => 1]);
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true, 'reseller_tier_id' => $tier->id]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        ResellerWalletTierChange::query()->create(['reseller_id' => $reseller->id, 'to_tier_id' => $tier->id, 'effective_on' => $first, 'applied_at' => now()]);
        $user = AffiliateUser::query()->create([
            'owner_type' => AccountOwnerType::Reseller->value, 'owner_id' => $reseller->id,
            'name' => 'Ali', 'email' => 'ali@example.com', 'password' => 'x', 'is_active' => true,
        ]);
        ResellerRebateLadder::query()->create(['effective_from' => $first, 'max_share_bp' => 3500, 'steps' => [
            ['threshold_sen' => 50000, 'rewards' => [(string) $tier->id => 100]],
        ]]);
        Order::query()->create([
            'placed_via' => 'reseller_api', 'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-REBATE-RACE-1', 'customer_email' => 'buyer@example.com', 'player_id' => '1',
            'wallet_reseller_id' => $reseller->id, 'cost_price' => 58260, 'standard_selling_price' => 60000, 'selling_price' => 60000,
            'transaction_fee' => 0, 'final_amount' => 60000, 'platform_profit' => 1740, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value,
            'recognised_at' => now()->subMinute(),
        ]);

        $resultFileA = tempnam(sys_get_temp_dir(), 'rebate_claim_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'rebate_claim_test_');
        $env = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox', 'DB_USERNAME' => 'kerox', 'DB_PASSWORD' => 'kerox',
        ];
        $command = fn (string $file) => [PHP_BINARY, 'artisan', 'app:rebate-test-claim', (string) $reseller->id, (string) $user->id, (string) $year, (string) $month, '1', $file];

        $processA = Process::path(base_path())->env($env)->start($command($resultFileA));
        $processB = Process::path(base_path())->env($env)->start($command($resultFileB));
        $processA->wait();
        $processB->wait();

        $outcomes = [file_get_contents($resultFileA), file_get_contents($resultFileB)];
        unlink($resultFileA);
        unlink($resultFileB);

        $this->assertCount(1, array_filter($outcomes, fn ($r) => $r === 'success'), 'Expected exactly one success, got: '.json_encode($outcomes));
        $this->assertCount(1, array_filter($outcomes, fn ($r) => str_starts_with($r, 'failed:')), 'Expected exactly one failure, got: '.json_encode($outcomes));
        $this->assertSame(100, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
        $this->assertSame(1, LedgerEntry::query()->where('type', 'wallet_rebate')->count());
    }
}
