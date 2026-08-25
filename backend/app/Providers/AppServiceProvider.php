<?php

namespace App\Providers;

use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\Fraud\CheckoutVelocityGuard;
use App\Services\Payment\Chip\ChipGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\Xendit\XenditGateway;
use App\Services\PlayerValidation\MlbbPlayerValidator;
use App\Services\PlayerValidation\PlayerValidatorRegistry;
use App\Services\PlayerValidation\Providers\AcidGameShopValidator;
use App\Services\PlayerValidation\Providers\MoogoldValidator;
use App\Services\PlayerValidation\Providers\NexoneValidator;
use App\Services\Supplier\CircuitBreakingSupplierAdapter;
use App\Services\Supplier\Digiflazz\DigiflazzAdapter;
use App\Services\Supplier\FakeSupplierAdapter;
use App\Services\Supplier\Gamevion\GamevionAdapter;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * SupplierAdapter is resolved per-supplier via SupplierAdapterFactory
     * (ADR-031, mirrors PaymentGatewayFactory below) — OrderFulfillmentService
     * looks up the order's own `supplier_id` and asks the factory for
     * the right implementation, rather than one global binding.
     *

     * PaymentGateway is resolved per-channel via PaymentGatewayFactory
     * (see that class + the payment_methods migration's doc comment)
     * rather than one hardcoded binding — CheckoutController looks up
     * the matched PaymentMethod row's `gateway` column and asks the
     * factory for the right implementation. PaymentGateway::class
     * itself stays bound to Xendit as a default, since the webhook
     * controller's route (/api/webhooks/xendit) is inherently
     * gateway-specific by URL, not resolved per-request.
     */
    public function register(): void
    {
        // ADR-023 decision #6: the real checkout->fulfillment pipeline
        // runs against a real, separately-booted server process during
        // Playwright E2E (not an in-process PHPUnit `Http::fake()`,
        // which can't reach a different process's HTTP client at all)
        // — so faking Gamevion for E2E has to happen at this container-
        // binding level instead, gated to a dedicated `e2e` environment
        // only. Reuses ADR-018's own FakeSupplierAdapter (already a
        // real, tested SupplierAdapter implementation with zero network
        // calls) rather than inventing a second fake. Always-success:
        // E2E's job is exercising the real UI/pipeline wiring, not
        // supplier failure handling, which PHPUnit's OrderFulfillmentService
        // tests already cover.
        //
        // ADR-031: SupplierAdapter::class's single global binding is
        // replaced by per-supplier `supplier-adapter.<slug>` bindings,
        // resolved through SupplierAdapterFactory (mirrors
        // PaymentGatewayFactory on the payment side) — the e2e branch
        // keeps its original SupplierAdapter::class binding unchanged
        // (decision #3: "stays exactly as it is") and additionally
        // registers the same Fake under `supplier-adapter.e2e-fake-supplier`,
        // the slug E2ESeeder actually gives its Supplier row, since
        // that's the key OrderFulfillmentService now resolves by.
        if ($this->app->environment('e2e')) {
            $this->app->bind(SupplierAdapter::class, fn () => new FakeSupplierAdapter(simulateSuccess: true));
            $this->app->bind('supplier-adapter.e2e-fake-supplier', fn () => new FakeSupplierAdapter(simulateSuccess: true));
        } else {
            $this->app->bind('supplier-adapter.gamevion', function () {
                $config = config('services.gamevion');
                $proxy = config('services.proxy');

                $gamevion = new GamevionAdapter(
                    baseUrl: $config['base_url'],
                    bearerToken: (string) $config['bearer_token'],
                    apiKey: (string) $config['api_key'],
                    sandbox: (bool) $config['sandbox'],
                    proxyUrl: $proxy['enabled'] ? $proxy['url'] : null,
                    timeoutSeconds: $config['timeout'],
                    connectTimeoutSeconds: $config['connect_timeout'],
                );

                // ADR-019 addendum, foundation-security.md §6, DASH-2 - a
                // decorator, not a change to GamevionAdapter itself, so any
                // future second supplier gets the same protection for free
                // just by being wrapped the same way at its own binding.
                // ADR-031 consequence ("breaker names must become
                // per-supplier"): satisfied structurally, not by
                // genericizing this closure — each supplier gets its own
                // `supplier-adapter.<slug>` binding with its own literal
                // breaker name matching that slug (see ADR-030's future
                // `supplier-adapter.digiflazz` binding for the second
                // instance of this same shape), so no two suppliers ever
                // share one breaker's failure count.
                $breakerConfig = config('services.circuit_breaker');

                return new CircuitBreakingSupplierAdapter(
                    inner: $gamevion,
                    breaker: new CircuitBreaker(
                        name: 'gamevion',
                        failureThreshold: $breakerConfig['failure_threshold'],
                        cooldownSeconds: $breakerConfig['cooldown_seconds'],
                    ),
                );
            });

            // ADR-030 — same CircuitBreakingSupplierAdapter wrapping
            // shape as 'gamevion' above, its own independent breaker
            // (name 'digiflazz', per ADR-031's consequence note this
            // comment predicted).
            $this->app->bind('supplier-adapter.digiflazz', function () {
                $config = config('services.digiflazz');
                $proxy = config('services.proxy');

                $digiflazz = new DigiflazzAdapter(
                    baseUrl: $config['base_url'],
                    username: (string) $config['username'],
                    apiKey: (string) $config['api_key'],
                    testing: (bool) $config['testing'],
                    customerNoSeparator: (string) $config['customer_no_separator'],
                    proxyUrl: $proxy['enabled'] ? $proxy['url'] : null,
                    timeoutSeconds: $config['timeout'],
                    connectTimeoutSeconds: $config['connect_timeout'],
                );

                $breakerConfig = config('services.circuit_breaker');

                return new CircuitBreakingSupplierAdapter(
                    inner: $digiflazz,
                    breaker: new CircuitBreaker(
                        name: 'digiflazz',
                        failureThreshold: $breakerConfig['failure_threshold'],
                        cooldownSeconds: $breakerConfig['cooldown_seconds'],
                    ),
                );
            });
        }

        $this->app->singleton(SupplierAdapterFactory::class);

        $this->app->bind('payment-gateway.xendit', function () {
            $config = config('services.xendit');

            return new XenditGateway(
                baseUrl: $config['base_url'],
                secretKey: (string) $config['secret_key'],
                webhookToken: (string) $config['webhook_token'],
                timeoutSeconds: $config['timeout'],
                connectTimeoutSeconds: $config['connect_timeout'],
            );
        });

        $this->app->bind('payment-gateway.chip', function () {
            $config = config('services.chip');

            return new ChipGateway(
                baseUrl: $config['base_url'],
                secretKey: (string) $config['secret_key'],
                brandId: (string) $config['brand_id'],
                timeoutSeconds: $config['timeout'],
                connectTimeoutSeconds: $config['connect_timeout'],
                webhookPublicKeyTtlSeconds: $config['webhook_public_key_ttl'],
            );
        });

        $this->app->singleton(PaymentGatewayFactory::class);

        $this->app->bind(PaymentGateway::class, fn ($app) => $app->make('payment-gateway.xendit'));

        // MLBB's validator chain — AcidGameShop -> Nexone -> MooGold,
        // priority order per the founder's own reliability ranking.
        // Bound under 'player-validator.mlbb' so PlayerValidatorRegistry
        // resolves it the same way it would any future validator key.
        $this->app->bind('player-validator.mlbb', function () {
            $config = config('services.player_validators');

            return new MlbbPlayerValidator([
                new AcidGameShopValidator(
                    baseUrl: $config['acidgameshop']['base_url'],
                    timeoutSeconds: $config['acidgameshop']['timeout'],
                ),
                new NexoneValidator(
                    baseUrl: $config['nexone']['base_url'],
                    timeoutSeconds: $config['nexone']['timeout'],
                ),
                new MoogoldValidator(
                    baseUrl: $config['moogold']['base_url'],
                    timeoutSeconds: $config['moogold']['timeout'],
                ),
            ]);
        });

        $this->app->singleton(PlayerValidatorRegistry::class);

        // ADR-007 / FRAUD-4
        $this->app->bind(CheckoutVelocityGuard::class, function () {
            $config = config('fraud.velocity');

            return new CheckoutVelocityGuard(
                threshold: $config['threshold'],
                windowMinutes: $config['window_minutes'],
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
