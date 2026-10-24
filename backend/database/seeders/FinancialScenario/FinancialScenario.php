<?php

namespace Database\Seeders\FinancialScenario;

use App\Models\AccountingPeriodClose;
use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\BudgetEnvelope;
use App\Models\CashAccount;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\Supplier;
use App\Services\Accounting\MonthCloseService;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Fulfillment\OrderSettlementService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Payment\Fake\FakePaymentGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Reseller\ResellerApiKeyService;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Supplier\SupplierOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ADR-127 decision 12: builds realistic money data by driving the real
 * system, never by writing order or ledger rows. Customer and partner
 * actions go through the real HTTP routes (checkout, the CHIP webhook,
 * the Reseller API), so prices are resolved server-side exactly as in
 * production; back-office actions call the admin's own services.
 *
 * Safe by construction: everything runs in this process (`sync` queue),
 * every outbound HTTP call is refused, mail and notifications are faked,
 * and the payment gateway and API supplier are zero-network fakes. It
 * refuses to run in production. Used by the invariant tests (a small
 * set) and by `FinancialScenarioSeeder` (the full local dev data set).
 *
 * Time is Malaysia wall-clock (`at('2026-09-30 23:59')`).
 */
final class FinancialScenario
{
    public readonly ScriptedSupplierAdapter $supplierAdapter;

    public readonly Supplier $apiSupplier;

    public readonly Affiliate $platform;

    public readonly Affiliate $affiliate;

    public readonly Reseller $reseller;

    public readonly AdminUser $admin;

    /** @var list<Package> */
    public readonly array $packages;

    private readonly string $apiKey;

    private int $sequence = 0;

    /** The world (catalog, brands, the reseller's opening top-up) is created at `$startsAt`, Malaysia time. */
    public static function boot(string $startsAt): self
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The financial scenario never runs in production.');
        }

        config(['queue.default' => 'sync', 'broadcasting.default' => 'null']);
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse($startsAt, 'Asia/Kuala_Lumpur')->utc());

        return new self;
    }

    private function __construct()
    {
        $this->supplierAdapter = new ScriptedSupplierAdapter;
        $gateway = new FakePaymentGateway;
        app()->instance(PaymentGateway::class, $gateway);
        app()->instance('payment-gateway.chip', $gateway);

        $this->apiSupplier = Supplier::query()->firstOrCreate(
            ['slug' => 'scenario-api'],
            ['name' => 'Scenario API Supplier', 'driver' => 'scenario-api', 'funding_mode' => Supplier::FUNDING_PREPAID, 'api_config' => [], 'currency' => 'MYR', 'is_active' => true],
        );
        app()->instance('supplier-adapter.scenario-api', $this->supplierAdapter);

        PaymentMethod::query()->firstOrCreate(
            ['channel_code' => 'FPX_SCN'],
            ['label' => 'Scenario FPX', 'category' => 'fpx', 'gateway' => 'chip', 'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100],
        );

        $this->admin = AdminUser::query()->firstOrCreate(
            ['email' => 'scenario-admin@pekangame.test'],
            ['name' => 'Scenario Admin', 'password' => Str::random(40), 'role' => 'super_admin', 'is_active' => true],
        );

        $this->platform = Affiliate::query()->firstOrCreate(
            ['is_primary' => true],
            ['business_name' => 'PekanGame', 'markup_pct' => 0, 'status' => 'active', 'is_owned' => true],
        );
        $this->affiliate = $this->affiliateBrand();
        $this->packages = $this->catalog();
        [$this->reseller, $this->apiKey] = $this->walletReseller();
    }

    /** Moves the clock to a Malaysia wall-clock time; every following action happens then. */
    public function at(string $malaysiaTime): self
    {
        Carbon::setTestNow(CarbonImmutable::parse($malaysiaTime, 'Asia/Kuala_Lumpur')->utc());

        return $this;
    }

    /** A guest checkout on the platform brand, or on the affiliate's own domain. */
    public function storefrontOrder(Package $package, bool $onAffiliateBrand = false): Order
    {
        $headers = $onAffiliateBrand ? ['X-Storefront-Host' => $this->affiliateHost()] : [];
        $n = ++$this->sequence;

        $this->call('POST', '/api/checkout', [
            'game_id' => $package->game_id,
            'package_id' => $package->id,
            'customer_email' => "buyer{$n}@scenario.test",
            'customer_name' => "Buyer {$n}",
            'customer_phone' => '60123'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'player_id' => (string) (100000000 + $n),
            'channel_code' => 'FPX_SCN',
            'idempotency_key' => (string) Str::uuid(),
        ], $headers);

        return Order::query()->latest('id')->firstOrFail();
    }

    /** CHIP reports the payment as paid; fulfillment runs in this process. */
    public function pay(Order $order): Order
    {
        $this->call('POST', '/api/webhooks/chip', [
            'event_type' => 'purchase.paid',
            'id' => $order->payment_ref,
            'reference' => $order->reference_number,
            'status' => 'paid',
            'purchase' => ['total' => $order->final_amount],
        ]);

        return $order->fresh();
    }

    /** An order through the Reseller REST API, paid from the wallet at placement. */
    public function resellerApiOrder(Package $package): Order
    {
        $n = ++$this->sequence;
        $this->call('POST', '/api/reseller/v1/orders', [
            'product_code' => $package->game->reseller_code.'-'.$package->denomination,
            'player_id' => (string) (200000000 + $n),
            'idempotency_key' => (string) Str::uuid(),
        ], ['Authorization' => "Bearer {$this->apiKey}"]);

        return Order::query()->latest('id')->firstOrFail();
    }

    /** The supplier reports a Pending order delivered (its webhook or the poll), at the order's catalog cost. */
    public function supplierDelivers(Order $order): Order
    {
        return app(OrderFulfillmentService::class)->finalizePendingDelivery(
            $order->fresh(),
            SupplierOutcome::Success,
            $order->supplier_ref,
            ['supplier_ref' => $order->supplier_ref, 'status' => 'delivered', 'price' => $order->cost_price / 100],
        );
    }

    /** The admin compensates a Failed order (store-credit voucher, ADR-024). */
    public function compensate(Order $order): Order
    {
        app(OrderSettlementService::class)->settle($order, $this->admin->id, 'Scenario compensation');

        return $order->fresh();
    }

    /** The admin closes a month as on the Monthly Summary: all profit to one envelope, the bank balance as counted. */
    public function closeMonth(int $year, int $month): AccountingPeriodClose
    {
        $closes = app(MonthCloseService::class);
        $preview = $closes->preview($year, $month);
        $envelope = BudgetEnvelope::query()->where('is_active', true)->orderBy('id')->firstOrFail();
        $bank = CashAccount::query()->firstOrCreate(['name' => 'Scenario Bank'], ['is_active' => true]);

        return $closes->close(
            $year,
            $month,
            $preview['allocate_sen'] === 0 ? [] : [['budget_envelope_id' => $envelope->id, 'amount_sen' => $preview['allocate_sen']]],
            CashAccount::query()->where('is_active', true)->get()->map(fn (CashAccount $a) => ['cash_account_id' => $a->id, 'balance_sen' => 0])->all(),
            'Scenario: the bank is not modelled',
            $this->admin->id,
        );
    }

    /** The wallet balance as the Reseller API reports it to the reseller. */
    public function apiBalanceSen(): int
    {
        return $this->call('GET', '/api/reseller/v1/balance', [], ['Authorization' => "Bearer {$this->apiKey}"])['balance_sen'];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function call(string $method, string $uri, array $payload, array $headers = []): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.0.'.intdiv($this->sequence, 250).'.'.($this->sequence % 250 + 1)];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create($uri, $method, server: $server, content: json_encode($payload));
        $response = app(Kernel::class)->handle($request);

        if ($response->getStatusCode() >= 300) {
            throw new RuntimeException("Scenario {$method} {$uri} answered {$response->getStatusCode()}: ".$response->getContent());
        }

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function affiliateHost(): string
    {
        return 'shop.scenario.test';
    }

    private function affiliateBrand(): Affiliate
    {
        $affiliate = Affiliate::query()->firstOrCreate(
            ['business_name' => 'Scenario Affiliate'],
            ['markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active'],
        );
        AffiliateDomain::query()->firstOrCreate(
            ['hostname' => $this->affiliateHost()],
            ['affiliate_id' => $affiliate->id, 'status' => AffiliateDomainStatus::Active, 'is_primary' => true],
        );

        return $affiliate;
    }

    /** @return list<Package> */
    private function catalog(): array
    {
        $game = Game::query()->firstOrCreate(
            ['slug' => 'scenario-mlbb'],
            ['name' => 'Scenario MLBB', 'is_active' => true, 'reseller_code' => 'SCN'],
        );

        return array_map(fn (array $p) => Package::query()->firstOrCreate(
            ['game_id' => $game->id, 'denomination' => $p[0]],
            ['name' => "{$p[0]} Diamonds", 'cost_price' => $p[1], 'standard_selling_price' => $p[2], 'markup_percent' => 0, 'is_active' => true, 'supplier_id' => $this->apiSupplier->id, 'supplier_package_ref' => "SCN-{$p[0]}"],
        ), [[86, 421, 500], [172, 842, 990], [429, 2105, 2450], [878, 4210, 4890]]);
    }

    /** @return array{Reseller, string} */
    private function walletReseller(): array
    {
        $tier = ResellerTier::query()->firstOrCreate(['name' => 'Scenario Gold'], ['markup_percent' => 5, 'is_active' => true, 'sort_order' => 99]);
        $reseller = Reseller::query()->firstOrCreate(['business_name' => 'Scenario Reseller'], ['reseller_tier_id' => $tier->id, 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        if (app(ResellerWalletService::class)->balance($reseller) === 0) {
            app(ResellerWalletService::class)->manualCredit($reseller, 500_000, 'Scenario opening top-up', null, $this->admin->id, 'scenario-opening-topup-'.$reseller->id);
        }

        return [$reseller, app(ResellerApiKeyService::class)->issue($reseller, 'Scenario key')['plainText']];
    }
}
