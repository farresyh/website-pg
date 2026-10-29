<?php

namespace Tests\Feature\Accounting;

use App\Models\AdminUser;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\WalletTopupAttempt;
use App\Services\Accounting\TransactionRegisterService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\ResellerClosureService;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-131 decision 17: the Register is the complete record of cash in and out. A bank-transfer
 * credit (no CHIP attempt behind it) and a closure refund are rows of their own, and a closed
 * reseller keeps its name.
 */
class TransactionRegisterResellerCashRowsTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(string $name = 'Register Reseller'): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => $name, 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }

    /** @return list<array<string, mixed>> */
    private function rows(?string $type = null): array
    {
        return app(TransactionRegisterService::class)->rows(now()->subDay(), now()->addDay(), $type);
    }

    public function test_a_manual_credit_is_a_cash_in_row(): void
    {
        $reseller = $this->reseller();
        $admin = AdminUser::factory()->superAdmin()->create();
        $entry = app(ResellerWalletService::class)->manualCredit($reseller, 250_000, 'bank transfer', null, $admin->id, 'register-key-1');

        $rows = $this->rows('reseller_wallet_manual_credit');

        $this->assertCount(1, $rows);
        $this->assertSame(250_000, $rows[0]['net_sen']);
        $this->assertSame(250_000, $rows[0]['gross_sen']);
        $this->assertSame(0, $rows[0]['fee_sen']);
        $this->assertSame('Ledger entry #'.$entry->id, $rows[0]['reference']);
        $this->assertStringContainsString('Register Reseller', $rows[0]['description']);
    }

    public function test_a_chip_top_up_is_not_also_listed_as_a_manual_credit(): void
    {
        $reseller = $this->reseller();
        PaymentMethod::query()->create(['channel_code' => 'fpx', 'label' => 'FPX', 'category' => 'fpx', 'gateway' => 'chip', 'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100]);
        $attempt = WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id, 'reference' => 'WT-REGISTER01', 'amount_sen' => 50_000, 'total_charged_sen' => 50_100,
            'channel_code' => 'fpx', 'status' => WalletTopupAttemptStatus::Paid->value, 'expires_at' => now()->addMinutes(30),
        ]);
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 50_000, 'wallet_topup', 'wallet_topup_attempt', $attempt->id);

        $this->assertCount(0, $this->rows('reseller_wallet_manual_credit'));
        $this->assertCount(1, $this->rows('reseller_wallet_topup'));
    }

    public function test_a_closure_refund_is_a_cash_out_row_and_the_closed_reseller_keeps_its_name(): void
    {
        $reseller = $this->reseller('Closed Reseller');
        $admin = AdminUser::factory()->superAdmin()->create();
        app(ResellerWalletService::class)->manualCredit($reseller, 250_000, null, null, $admin->id, 'register-key-2');
        app(ResellerClosureService::class)->close($reseller, $admin->id, now('Asia/Kuala_Lumpur')->toDateString(), 'MBB-777');

        $closure = $this->rows('reseller_wallet_closure');
        $manual = $this->rows('reseller_wallet_manual_credit');

        $this->assertCount(1, $closure);
        $this->assertSame(-250_000, $closure[0]['net_sen']);
        $this->assertStringContainsString('Closed Reseller', $closure[0]['description']);
        $this->assertStringContainsString('MBB-777', $closure[0]['description']);
        $this->assertStringContainsString('Closed Reseller', $manual[0]['description']);
    }

    public function test_a_closed_resellers_chip_top_up_row_still_names_it(): void
    {
        $reseller = $this->reseller('Gone Reseller');
        $admin = AdminUser::factory()->superAdmin()->create();
        PaymentMethod::query()->create(['channel_code' => 'fpx', 'label' => 'FPX', 'category' => 'fpx', 'gateway' => 'chip', 'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100]);
        $attempt = WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id, 'reference' => 'WT-GONE00001', 'amount_sen' => 50_000, 'total_charged_sen' => 50_100,
            'channel_code' => 'fpx', 'status' => WalletTopupAttemptStatus::Paid->value, 'expires_at' => now()->addMinutes(30),
        ]);
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 50_000, 'wallet_topup', 'wallet_topup_attempt', $attempt->id);
        app(ResellerClosureService::class)->close($reseller, $admin->id, now('Asia/Kuala_Lumpur')->toDateString(), 'MBB-888');

        $this->assertStringContainsString('Gone Reseller', $this->rows('reseller_wallet_topup')[0]['description']);
        $this->assertStringNotContainsString('unknown reseller', $this->rows('reseller_wallet_topup')[0]['description']);
    }
}
