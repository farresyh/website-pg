<?php

namespace Tests\Feature\Services\Fraud;

use App\Services\Fraud\CheckoutVelocityGuard;
use Tests\TestCase;

class CheckoutVelocityGuardTest extends TestCase
{
    public function test_starts_allowed(): void
    {
        $guard = new CheckoutVelocityGuard(threshold: 3, windowMinutes: 10);

        $this->assertFalse($guard->tooManyRecentHits('203.0.113.'.uniqid()));
    }

    public function test_trips_after_reaching_the_threshold(): void
    {
        $ip = '203.0.113.'.uniqid();
        $guard = new CheckoutVelocityGuard(threshold: 3, windowMinutes: 10);

        $guard->recordHit($ip);
        $guard->recordHit($ip);
        $this->assertFalse($guard->tooManyRecentHits($ip));

        $guard->recordHit($ip);

        $this->assertTrue($guard->tooManyRecentHits($ip));
    }

    public function test_different_ips_do_not_share_state(): void
    {
        $ipA = '203.0.113.'.uniqid();
        $ipB = '203.0.113.'.uniqid();
        $guard = new CheckoutVelocityGuard(threshold: 1, windowMinutes: 10);

        $guard->recordHit($ipA);

        $this->assertTrue($guard->tooManyRecentHits($ipA));
        $this->assertFalse($guard->tooManyRecentHits($ipB));
    }

    public function test_resets_after_the_window_elapses(): void
    {
        $ip = '203.0.113.'.uniqid();
        $guard = new CheckoutVelocityGuard(threshold: 1, windowMinutes: 10);

        $guard->recordHit($ip);
        $this->assertTrue($guard->tooManyRecentHits($ip));

        $this->travel(11)->minutes();

        $this->assertFalse($guard->tooManyRecentHits($ip));
    }
}
