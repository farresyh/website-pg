<?php

namespace App\Providers;

use App\Services\Payment\PaymentGateway;
use App\Services\Payment\Xendit\XenditGateway;
use App\Services\Supplier\Gamevion\GamevionAdapter;
use App\Services\Supplier\SupplierAdapter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Single-supplier/single-gateway bindings for now — matches MVP
     * reality (one Gamevion, one Xendit). Once the Supplier model is
     * wired up for real multi-supplier routing, SupplierAdapter
     * resolution moves to a per-order factory instead of one global
     * binding; this is a deliberate placeholder, not the final shape.
     */
    public function register(): void
    {
        $this->app->bind(SupplierAdapter::class, function () {
            $config = config('services.gamevion');

            return new GamevionAdapter(
                baseUrl: $config['base_url'],
                bearerToken: (string) $config['bearer_token'],
                apiKey: (string) $config['api_key'],
                sandbox: (bool) $config['sandbox'],
            );
        });

        $this->app->bind(PaymentGateway::class, function () {
            $config = config('services.xendit');

            return new XenditGateway(
                baseUrl: $config['base_url'],
                secretKey: (string) $config['secret_key'],
                webhookToken: (string) $config['webhook_token'],
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
