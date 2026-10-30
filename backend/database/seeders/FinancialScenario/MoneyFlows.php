<?php

namespace Database\Seeders\FinancialScenario;

use App\Http\Controllers\Admin\WithdrawalController;
use App\Models\BudgetEnvelope;
use App\Models\ChipSettledTransaction;
use App\Models\CurrencyRate;
use App\Models\Membership;
use App\Models\MembershipCheckoutAttempt;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\Supplier;
use App\Models\WalletTopupAttempt;
use App\Models\Withdrawal;
use App\Services\Accounting\BudgetEnvelopeService;
use App\Services\Accounting\EnvelopePostingType;
use App\Services\Accounting\FundType;
use App\Services\Accounting\PaidFrom;
use App\Services\Accounting\SettlementReconciliationService;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Fulfillment\OrderResendService;
use App\Services\Ledger\LedgerService;
use App\Services\Membership\MembershipSessionTokenService;
use App\Services\Order\PaymentStatus;
use App\Services\Reseller\ResellerWalletTopupService;
use App\Services\Withdrawal\WithdrawalStatus;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-127 decision 12, the money that moves around the orders: the
 * company's bank, CHIP (its fee at payment and its settlement files),
 * membership, CHIP wallet top-ups, affiliate withdrawals, resends and an
 * IDR supplier's exchange rate. Each goes through the real service,
 * route or admin action, as in production.
 *
 * The bank is modelled as dated flows, so a month close can enter the real
 * balance and the cash equation's gap is a test of every page at once.
 */
trait MoneyFlows
{
    /** @var list<array{string, int}> Malaysia date, sen */
    private array $bankFlows = [];

    /** The bank balance at the end of a Malaysia day. */
    public function bankAsOf(string $date): int
    {
        return array_sum(array_map(fn (array $f) => $f[0] <= $date ? $f[1] : 0, $this->bankFlows));
    }

    private function bank(int $sen): void
    {
        $this->bankFlows[] = [now('Asia/Kuala_Lumpur')->toDateString(), $sen];
    }

    /** What CHIP keeps from a payment: about RM1, a few sen either way, so the processing gain or loss is never trivially 0. */
    public static function chipFee(int $totalSen): int
    {
        return 90 + $totalSen % 25;
    }

    /** CHIP's paid callback with its own paid time and fee (ADR-127 F1, F2), for an order, a membership or a wallet top-up. */
    private function chipPaid(string $paymentId, ?string $reference, int $totalSen): void
    {
        $this->call('POST', '/api/webhooks/chip', [
            'event_type' => 'purchase.paid',
            'id' => $paymentId,
            'reference' => $reference,
            'status' => 'paid',
            'purchase' => ['total' => $totalSen],
            'payment' => ['paid_on' => now()->getTimestamp(), 'fee_amount' => self::chipFee($totalSen)],
        ]);
    }

    /** A director puts share capital into the company: the bank and the first envelope. */
    public function fundCompany(int $sen): void
    {
        app(BudgetEnvelopeService::class)->post(
            EnvelopePostingType::Funding,
            [['budget_envelope_id' => BudgetEnvelope::query()->where('is_active', true)->orderBy('id')->value('id'), 'amount_sen' => $sen]],
            now('Asia/Kuala_Lumpur')->toDateString(),
            'Scenario: share capital',
            $this->admin->id,
            PaidFrom::Farres,
            FundType::ShareCapital,
        );
        $this->bank($sen);
    }

    /**
     * CHIP pays out everything paid by now and not yet settled, net of its
     * fee, as one settlement file through the real ingest. "By now" keeps a
     * scenario that scripts days out of order from settling the future.
     */
    public function settleChip(): void
    {
        $settled = ChipSettledTransaction::query()->pluck('transaction_id')->all();
        $rows = [
            ...Order::query()->where('payment_status', PaymentStatus::Paid->value)->where('is_test', false)
                ->whereNotNull('payment_ref')->where('final_amount', '>', 0)->where('paid_at', '<=', now())->whereNotIn('payment_ref', $settled)
                ->get()->map(fn (Order $o) => ['id' => $o->payment_ref, 'reference' => $o->reference_number, 'amount_sen' => $o->final_amount, 'fee_sen' => $o->gateway_fee_sen, 'paid' => $o->paid_at]),
            // A membership or a top-up is dated by its ledger fact (ADR-127 F5), as paidButNotSettled reads it.
            ...MembershipCheckoutAttempt::query()->with('feeRecord')->whereHas('feeRecord', fn ($q) => $q->where('created_at', '<=', now()))->whereNotIn('payment_ref', $settled)
                ->get()->map(fn (MembershipCheckoutAttempt $m) => ['id' => $m->payment_ref, 'reference' => $m->subscription_number, 'amount_sen' => $m->total_charged_sen, 'fee_sen' => $m->gateway_fee_sen, 'paid' => $m->feeRecord->created_at]),
            ...WalletTopupAttempt::query()->with('ledgerCredit')->whereHas('ledgerCredit', fn ($q) => $q->where('created_at', '<=', now()))->whereNotIn('chip_payment_ref', $settled)
                ->get()->map(fn (WalletTopupAttempt $w) => ['id' => $w->chip_payment_ref, 'reference' => $w->reference, 'amount_sen' => $w->total_charged_sen, 'fee_sen' => $w->gateway_fee_sen, 'paid' => $w->ledgerCredit->created_at]),
        ];

        if ($rows === []) {
            return;
        }

        $today = now('Asia/Kuala_Lumpur');
        $from = collect($rows)->min('paid')->copy()->setTimezone('Asia/Kuala_Lumpur')->toDateString();
        $path = ChipSettlementFile::write($from, $today->toDateString(), $today->format('Y-m-d H:i'), $rows);
        app(SettlementReconciliationService::class)->ingest(new UploadedFile($path, 'chip-settlement.xlsx', null, null, true), $this->admin->id);
        $this->bank(array_sum(array_map(fn (array $r) => $r['amount_sen'] - $r['fee_sen'], $rows)));
    }

    /** Membership switched on, platform-wide and on the PekanGame brand (ADR-068). */
    public function enableMembership(): void
    {
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $this->platform->update(['membership_enabled' => true]);
    }

    /** A storefront customer subscribes through the real `/membership/subscribe` and pays through CHIP. */
    public function subscribeMember(int $customer, string $plan = 'Tier 2'): Membership
    {
        $response = $this->call('POST', '/api/membership/subscribe', [
            'membership_plan_id' => MembershipPlan::query()->where('name', $plan)->value('id'),
            'payment_method' => 'FPX_SCN',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ], ['Authorization' => 'Bearer '.$this->memberToken($customer)]);

        $attempt = MembershipCheckoutAttempt::query()->where('subscription_number', $response['subscription_number'])->firstOrFail();
        $this->chipPaid($attempt->payment_ref, $attempt->subscription_number, $attempt->total_charged_sen);

        return Membership::query()->where('email', "buyer{$customer}@scenario.test")->latest('id')->firstOrFail();
    }

    private function memberToken(int $customer): string
    {
        return app(MembershipSessionTokenService::class)->issue($this->platform->id, "buyer{$customer}@scenario.test");
    }

    /** The reseller tops up the wallet by FPX from the portal (ADR-073), paid through CHIP. */
    public function walletTopupViaChip(int $amountSen): void
    {
        $attempt = app(ResellerWalletTopupService::class)->initiate($this->reseller, $amountSen, 'FPX_SCN', []);
        $this->chipPaid($attempt->chip_payment_ref, $attempt->reference, $attempt->total_charged_sen);
    }

    /**
     * The affiliate is paid out: a Pending ticket as the monthly payout opens it (ADR-132, no requester), approved
     * and paid by the admin. The ticket row is written directly so the scenario can pick the amount; the opener's
     * own rules are proven in AffiliateMonthlyPayoutTest.
     */
    public function affiliateWithdraws(int $amountSen): Withdrawal
    {
        app(LedgerService::class)->openAccount('affiliate', $this->affiliate->id);
        $withdrawal = Withdrawal::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $this->affiliate->id, 'amount' => $amountSen,
            'bank_name' => 'Scenario Bank', 'bank_account_no' => '1234567890', 'bank_account_holder' => 'Scenario Affiliate',
            'status' => WithdrawalStatus::Pending,
        ]);

        $request = Request::create('/', 'PATCH');
        $request->setUserResolver(fn () => $this->admin);
        $controller = app(WithdrawalController::class);
        $controller->approve($request, $withdrawal);
        $controller->complete($request, $withdrawal);
        $this->bank(-$amountSen);

        return $withdrawal->fresh();
    }

    /** The admin resends a Failed order on its own package (ADR-105). */
    public function resend(Order $order): Order
    {
        $order = $order->fresh();

        return app(OrderResendService::class)->resend($order, $order->package, 'Scenario resend', $this->admin->name);
    }

    /** Today's market IDR→MYR rate, as the FX API would have stored it. */
    public function setIdrRate(float $myrPerIdr): void
    {
        CurrencyRate::query()->create(['from' => 'IDR', 'to' => 'MYR', 'rate' => $myrPerIdr, 'source' => 'scenario', 'fetched_at' => now()]);
        Cache::forget('currency_rate:IDR:MYR');
    }

    /** Ringgit sent through Wise to the IDR supplier, landing at a deposit rate of its own. */
    public function fundIdrSupplier(int $myrSen, float $idrPerMyr, int $feeSen = 0): void
    {
        app(SupplierFundingService::class)->recordTransfer(
            $this->idrSupplier, now('Asia/Kuala_Lumpur')->toDateString(), 'wise', $myrSen, $feeSen, 'IDR',
            number_format($myrSen / 100 * $idrPerMyr, 4, '.', ''), null, 'SCN-IDR-'.(++$this->sequence), null, $this->admin->id, PaidFrom::CompanyAccount,
        );
        $this->bank(-$myrSen - $feeSen);
    }

    private function idrRate(): float
    {
        return (float) CurrencyRate::query()->where('from', 'IDR')->where('to', 'MYR')->latest('fetched_at')->latest('id')->value('rate');
    }

    /** @param  Supplier  $supplier */
    private function adapterFor(?Supplier $supplier): ScriptedSupplierAdapter
    {
        return $supplier?->id === $this->idrSupplier->id ? $this->idrAdapter : $this->supplierAdapter;
    }
}
