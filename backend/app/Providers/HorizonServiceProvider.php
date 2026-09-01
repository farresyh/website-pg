<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     *
     * ADR-048 addendum: $user resolves via the `web` session guard
     * (AdminUser, config/auth.php), reached only through
     * OpsAccessController's signed-link session bootstrap — this backend
     * has no other route into a `web` session. super_admin only, matching
     * every other /middleware/* ops-tooling screen's own gate
     * (Backups/Suppliers/Price Sync).
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return $user !== null && $user->is_active && $user->role === 'super_admin';
        });
    }
}
