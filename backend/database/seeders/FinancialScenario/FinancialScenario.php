<?php

namespace Database\Seeders\FinancialScenario;

use App\Models\AccountingPeriodClose;
use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\BudgetEnvelope;
use App\Models\CashAccount;
use App\Models\Game;
use App\Models\JokiSetting;
use App\Models\JokiTier;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\Supplier;
use App\Models\WhatsAppGroup;
use App\Services\Accounting\MonthCloseService;
use App\Services\Accounting\PaidFrom;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Fulfillment\ManualDeliveryService;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Joki\JokiAssignmentService;
use App\Services\Joki\JokiCatalog;
use App\Services\Fulfillment\OrderSettlementService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\PaymentStatus;
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

        // The array cache keeps rate limits from leaking between runs on a
        // dev DB whose cache store is the database (the clock is replayed).
        config(['queue.default' => 'sync', 'broadcasting.default' => 'null', 'cache.default' => 'array']);
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
    public function storefrontOrder(Package $package, bool $onAffiliateBrand = false, ?int $customer = null, ?string $voucherCode = null): Order
    {
        $headers = $onAffiliateBrand ? ['X-Storefront-Host' => $this->affiliateHost()] : [];
        $n = ++$this->sequence;
        $c = $customer ?? $n;

        $this->call('POST', '/api/checkout', array_filter([
            'game_id' => $package->game_id,
            'package_id' => $package->id,
            'customer_email' => "buyer{$c}@scenario.test",
            'customer_name' => "Buyer {$c}",
            'customer_phone' => '60123'.str_pad((string) $c, 6, '0', STR_PAD_LEFT),
            'player_id' => (string) (100000000 + $c),
            'channel_code' => 'FPX_SCN',
            'idempotency_key' => (string) Str::uuid(),
            'voucher_code' => $voucherCode,
        ]), $headers);

        return Order::query()->latest('id')->firstOrFail();
    }

    /** CHIP reports the payment as paid; fulfillment runs in this process. */
    public function pay(Order $order): Order
    {
        // A voucher that covers the whole order pays it at checkout; CHIP never sees it.
        if ($order->payment_status !== PaymentStatus::Pending) {
            return $order->fresh();
        }

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

    /** The admin confirms a NeedsReview order on the supplier's dashboard: its invoice and the price charged, in ringgit. */
    public function adminConfirmsDelivered(Order $order, int $chargedSen): Order
    {
        return app(OrderFulfillmentService::class)->markDeliveredManually(
            $order->fresh(),
            'SCN-INV-'.$order->id,
            $chargedSen / 100,
            'Scenario: matched on the supplier dashboard',
            $this->admin->name,
        );
    }

    /**
     * Joki switched on as the admin does it (ADR-126): the catalog, the
     * panel switch (and the payout share, when given) and the platform brand. Returns the penjoki, each a
     * pay-after manual supplier with its own WhatsApp group.
     *
     * @return list<Supplier>
     */
    public function enableJoki(?float $payoutSharePct = null, int $penjokiCount = 2): array
    {
        app(JokiCatalog::class)->ensure();
        // A null share keeps the panel's own settings (the dev DB's).
        $settings = JokiSetting::current();
        $settings->update(array_filter([
            'is_enabled' => true,
            'payout_share_pct' => $payoutSharePct,
            'terms_html' => blank($settings->terms_html) ? '<p>Scenario terms.</p>' : null,
        ], fn ($v) => $v !== null));
        $this->platform->update(['joki_enabled' => true]);

        return array_map(function (int $i) {
            $penjoki = Supplier::query()->firstOrCreate(['slug' => "scenario-penjoki-{$i}"], [
                'name' => "Scenario Penjoki {$i}", 'driver' => Supplier::DRIVER_MANUAL, 'funding_mode' => Supplier::FUNDING_PAY_AFTER,
                'api_config' => [], 'currency' => 'MYR', 'joki_role' => Supplier::JOKI_PENJOKI, 'is_active' => true,
            ]);
            WhatsAppGroup::query()->firstOrCreate(['owner_type' => 'supplier', 'owner_id' => $penjoki->id], ['whatsapp_group_id' => "scenario-penjoki-{$i}@g.us"]);

            return $penjoki;
        }, range(1, $penjokiCount));
    }

    /**
     * A joki checkout on the platform brand through the one /checkout,
     * priced by the server from the climb (ADR-126). A rank is
     * [tier name, division or null, stars].
     *
     * @param  array{0: string, 1: ?int, 2: int}  $from
     * @param  array{0: string, 1: ?int, 2: int}  $to
     */
    public function jokiOrder(array $from, array $to, bool $express = false): Order
    {
        $calculator = app(JokiCatalog::class)->ensure();
        $n = ++$this->sequence;
        $rank = fn (array $r) => ['tier_id' => JokiTier::query()->where('name', $r[0])->value('id'), 'division' => $r[1], 'stars' => $r[2]];

        $this->call('POST', '/api/checkout', [
            'game_id' => $calculator->game_id,
            'package_id' => $calculator->id,
            'customer_email' => "joki{$n}@scenario.test",
            'customer_name' => "Joki Buyer {$n}",
            'customer_phone' => '60124'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'channel_code' => 'FPX_SCN',
            'idempotency_key' => (string) Str::uuid(),
            'credentials' => ['login_type' => 'Moonton', 'user_id' => (string) (300000000 + $n), 'login' => "player{$n}", 'password' => 'scenario-secret'],
            'credential_consent' => true,
            'joki' => ['from' => $rank($from), 'to' => $rank($to), 'is_express' => $express, 'hero_request' => 'Ling', 'role' => 'Jungler', 'play_window' => '8pm – 12am'],
        ]);

        return Order::query()->latest('id')->firstOrFail();
    }

    /** The admin gives a paid joki to a penjoki (or moves it to another). */
    public function assignJoki(Order $order, Supplier $penjoki): Order
    {
        return app(JokiAssignmentService::class)->assign($order->fresh(), $penjoki->id, $this->admin->name);
    }

    /** The penjoki sends `.done`: delivered at the agreed payout. */
    public function penjokiDelivers(Order $order): Order
    {
        $order = $order->fresh();

        return app(ManualDeliveryService::class)->confirmDelivered($order, null, null, 'penjoki', $order->supplier_id, 'supplier_bot');
    }

    /** The penjoki sends `.gagal`: the order fails and the penjoki is owed nothing. */
    public function penjokiFails(Order $order, string $reason = 'Scenario: could not climb'): Order
    {
        $order = $order->fresh();

        return app(ManualDeliveryService::class)->confirmFailed($order, $reason, 'penjoki', $order->supplier_id, 'supplier_bot');
    }

    /** The founder records a bank transfer to a supplier: funding an API supplier, or paying a penjoki what is owed (MYR). */
    public function supplierTransfer(Supplier $supplier, int $amountSen): void
    {
        app(SupplierFundingService::class)->recordTransfer(
            $supplier, now('Asia/Kuala_Lumpur')->toDateString(), 'bank', $amountSen, 0, 'MYR',
            number_format($amountSen / 100, 2, '.', ''), null, 'SCN-TRF-'.(++$this->sequence), null, $this->admin->id, PaidFrom::CompanyAccount,
        );
    }

    /** The admin credits the reseller's wallet after a bank transfer. */
    public function topUpWallet(int $amountSen): void
    {
        app(ResellerWalletService::class)->manualCredit($this->reseller, $amountSen, 'Scenario top-up', null, $this->admin->id, 'scenario-topup-'.Str::uuid());
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
