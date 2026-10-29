<?php

namespace Tests\Feature\Http\Controllers\ResellerPortal;

use App\Models\AffiliateUser;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\ResellerWalletReceiptService;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-131 decisions 7 and 9-11: the limits the portal reads from the API,
 * the 422 sentences, and the Payment Receipt (own receipts only, the fee
 * the reseller bore, never CHIP's own fee).
 */
class PurchaseBalancePortalTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(string $name = 'Receipt Reseller'): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => $name, 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }

    private function tokenFor(Reseller $reseller): string
    {
        $user = AffiliateUser::query()->create([
            'owner_type' => 'reseller', 'owner_id' => $reseller->id,
            'name' => 'Staff', 'email' => 'staff+'.$reseller->id.'@pb.test',
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);

        return $user->createToken('affiliate')->plainTextToken;
    }

    /** A CHIP top-up that was paid: the attempt row plus the credit it wrote. */
    private function paidTopup(Reseller $reseller, int $amount = 50_000, int $charged = 50_100, ?int $chipFee = 350): WalletTopupAttempt
    {
        PaymentMethod::query()->firstOrCreate(['channel_code' => 'fpx'], [
            'label' => 'Online Banking (FPX)', 'category' => 'fpx', 'gateway' => 'chip',
            'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100,
        ]);
        $attempt = WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id, 'reference' => 'WT-ABC123DEF4', 'amount_sen' => $amount,
            'total_charged_sen' => $charged, 'channel_code' => 'fpx',
            'status' => WalletTopupAttemptStatus::Paid->value, 'gateway_fee_sen' => $chipFee,
            'expires_at' => now()->addMinutes(30),
        ]);
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, $amount, 'wallet_topup', 'wallet_topup_attempt', $attempt->id);

        return $attempt;
    }

    public function test_the_wallet_response_carries_the_limits_and_headroom(): void
    {
        $reseller = $this->reseller();
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 2_500_000, 'wallet_topup');

        $this->withToken($this->tokenFor($reseller))->getJson('/api/reseller-portal/wallet')
            ->assertOk()
            ->assertJsonPath('limits.min_topup_sen', 1_000)
            ->assertJsonPath('limits.max_topup_sen', 1_000_000)
            ->assertJsonPath('limits.balance_cap_sen', 10_000_000)
            ->assertJsonPath('limits.headroom_sen', 7_500_000);
    }

    public function test_a_top_up_over_the_maximum_is_a_422_that_names_the_maximum(): void
    {
        PaymentMethod::query()->create(['channel_code' => 'fpx', 'label' => 'FPX', 'category' => 'fpx', 'gateway' => 'chip', 'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100]);
        $reseller = $this->reseller();

        $this->withToken($this->tokenFor($reseller))->postJson('/api/reseller-portal/wallet/topup', ['amount_sen' => 1_000_001, 'channel_code' => 'fpx'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The most you can add in one go is RM10,000.00.');
    }

    public function test_a_top_up_over_the_balance_cap_is_a_422_that_names_the_headroom(): void
    {
        PaymentMethod::query()->create(['channel_code' => 'fpx', 'label' => 'FPX', 'category' => 'fpx', 'gateway' => 'chip', 'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100]);
        $reseller = $this->reseller();
        $reseller->update(['balance_cap_sen' => 300_000]);
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 250_000, 'wallet_topup');

        $this->withToken($this->tokenFor($reseller))->postJson('/api/reseller-portal/wallet/topup', ['amount_sen' => 60_000, 'channel_code' => 'fpx'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This would take your Purchase Balance above its limit of RM3,000.00. You can add up to RM500.00 now.');
    }

    public function test_a_paid_top_up_is_listed_with_its_receipt_number_and_a_manual_credit_with_its_own(): void
    {
        $reseller = $this->reseller();
        $this->paidTopup($reseller);
        $manual = app(ResellerWalletService::class)->manualCredit($reseller, 100_000, 'bank transfer', null, 1, 'k1');

        $numbers = collect($this->withToken($this->tokenFor($reseller))->getJson('/api/reseller-portal/wallet')->assertOk()->json('entries.data'))
            ->pluck('receipt_number')->all();

        $this->assertEqualsCanonicalizing(['PB-WT-ABC123DEF4', 'PB-M-'.$manual->id], $numbers);
    }

    public function test_a_reseller_downloads_their_own_receipt_as_a_pdf(): void
    {
        $reseller = $this->reseller();
        $this->paidTopup($reseller);

        $response = $this->withToken($this->tokenFor($reseller))->get('/api/reseller-portal/wallet/receipts/PB-WT-ABC123DEF4');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_another_resellers_receipt_is_not_found(): void
    {
        $owner = $this->reseller('Owner');
        $this->paidTopup($owner);
        $other = $this->reseller('Other');

        $this->withToken($this->tokenFor($other))->get('/api/reseller-portal/wallet/receipts/PB-WT-ABC123DEF4')->assertNotFound();
    }

    public function test_an_unpaid_or_unknown_receipt_is_not_found(): void
    {
        $reseller = $this->reseller();
        $attempt = $this->paidTopup($reseller);
        $attempt->update(['status' => WalletTopupAttemptStatus::Pending->value]);
        $token = $this->tokenFor($reseller);

        $this->withToken($token)->get('/api/reseller-portal/wallet/receipts/PB-WT-ABC123DEF4')->assertNotFound();
        $this->withToken($token)->get('/api/reseller-portal/wallet/receipts/PB-M-99999')->assertNotFound();
        $this->withToken($token)->get('/api/reseller-portal/wallet/receipts/not-a-number')->assertNotFound();
    }

    public function test_a_chip_credit_is_not_also_reachable_as_a_manual_receipt(): void
    {
        $reseller = $this->reseller();
        $this->paidTopup($reseller);
        $chipCredit = LedgerEntry::query()->where('reference_type', 'wallet_topup_attempt')->firstOrFail();

        $this->assertNull(app(ResellerWalletReceiptService::class)->forNumber($reseller, 'PB-M-'.$chipCredit->id));
    }

    public function test_the_receipt_fee_is_what_the_reseller_bore_never_the_gateways_own_fee(): void
    {
        $reseller = $this->reseller();
        $this->paidTopup($reseller, amount: 50_000, charged: 50_100, chipFee: 350);

        $data = app(ResellerWalletReceiptService::class)->forNumber($reseller, 'PB-WT-ABC123DEF4');

        $this->assertSame(100, $data['fee_sen']);
        $this->assertSame(50_100, $data['total_sen']);
        $this->assertSame(50_000, $data['amount_sen']);
        $this->assertStringNotContainsString('350', json_encode($data));
    }

    public function test_a_manual_credit_receipt_has_no_fee_and_the_seller_details_come_from_the_settings(): void
    {
        PlatformSettings::current()->update(['company_legal_name' => 'LWF Group Sdn. Bhd.', 'company_reg_no' => '202501000001', 'company_address' => '1 Jalan Test']);
        $reseller = $this->reseller();
        $entry = app(ResellerWalletService::class)->manualCredit($reseller, 100_000, null, null, 1, 'k2');

        $data = app(ResellerWalletReceiptService::class)->forNumber($reseller, 'PB-M-'.$entry->id);

        $this->assertSame(0, $data['fee_sen']);
        $this->assertSame(100_000, $data['total_sen']);
        $this->assertSame('LWF Group Sdn. Bhd.', $data['seller']['name']);
        $this->assertSame('202501000001', $data['seller']['reg_no']);
    }

    public function test_the_receipt_makes_no_claim_that_an_invoice_is_issued_per_order(): void
    {
        // The system issues no per-order invoice (ADR-083 decision 6: e-Invoicing is outside our code),
        // so a document given to a payment provider must not say it does.
        $reseller = $this->reseller();
        $this->paidTopup($reseller);
        $data = app(ResellerWalletReceiptService::class)->forNumber($reseller, 'PB-WT-ABC123DEF4');

        $html = view('receipts.purchase-balance', $data)->render();

        $this->assertStringContainsString('not an invoice', $html);
        $this->assertStringNotContainsString('An invoice is issued', $html);
    }
}
