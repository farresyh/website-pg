<?php

namespace App\Providers;

use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\Fraud\CheckoutVelocityGuard;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\Xendit\XenditGateway;
use App\Services\PlayerValidation\MlbbPlayerValidator;
use App\Services\PlayerValidation\PlayerValidatorRegistry;
use App\Services\PlayerValidation\Providers\AcidGameShopValidator;
use App\Services\PlayerValidation\Providers\MoogoldValidator;
use App\Services\PlayerValidation\Providers\NexoneValidator;
use App\Services\Supplier\CircuitBreakingSupplierAdapter;
use App\Services\Supplier\Gamevion\GamevionAdapter;
use App\Services\Supplier\SupplierAdapter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Single-supplier binding for now — matches MVP reality (one
     * Gamevion). Once the Supplier model is wired up for real
     * multi-supplier routing, SupplierAdapter resolution moves to a
     * per-order factory instead of one global binding; this is a
     * deliberate placeholder, not the final shape.
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
        $this->app->bind(SupplierAdapter::class, function () {
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
