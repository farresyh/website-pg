<?php

namespace Tests\Feature\Services\CircuitBreaker;

use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\CircuitBreaker\CircuitBreakerState;
use Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    public function test_starts_closed(): void
    {
        $breaker = new CircuitBreaker('test-'.uniqid(), failureThreshold: 3, cooldownSeconds: 60);

        $this->assertFalse($breaker->isOpen());
        $this->assertSame(CircuitBreakerState::Closed, $breaker->state());
    }

    public function test_opens_after_reaching_the_failure_threshold(): void
    {
        $breaker = new CircuitBreaker('test-'.uniqid(), failureThreshold: 3, cooldownSeconds: 60);

        $breaker->recordFailure();
        $breaker->recordFailure();
        $this->assertFalse($breaker->isOpen());

        $breaker->recordFailure();

        $this->assertTrue($breaker->isOpen());
        $this->assertSame(CircuitBreakerState::Open, $breaker->state());
    }

    public function test_a_success_before_the_threshold_resets_the_failure_count(): void
    {
        $breaker = new CircuitBreaker('test-'.uniqid(), failureThreshold: 3, cooldownSeconds: 60);

        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordSuccess();
        $breaker->recordFailure();
        $breaker->recordFailure();

        $this->assertFalse($breaker->isOpen());
    }

    public function test_closes_again_once_the_cooldown_elapses(): void
    {
        $breaker = new CircuitBreaker('test-'.uniqid(), failureThreshold: 1, cooldownSeconds: 30);

        $breaker->recordFailure();
        $this->assertTrue($breaker->isOpen());

        $this->travel(31)->seconds();

        $this->assertFalse($breaker->isOpen());
    }

    public function test_a_failure_after_cooldown_reopens_the_breaker(): void
    {
        $breaker = new CircuitBreaker('test-'.uniqid(), failureThreshold: 1, cooldownSeconds: 30);

        $breaker->recordFailure();
        $this->travel(31)->seconds();
        $this->assertFalse($breaker->isOpen());

        $breaker->recordFailure();

        $this->assertTrue($breaker->isOpen());
    }

    public function test_a_success_after_cooldown_fully_closes_the_breaker(): void
    {
        $breaker = new CircuitBreaker('test-'.uniqid(), failureThreshold: 1, cooldownSeconds: 30);

        $breaker->recordFailure();
        $this->travel(31)->seconds();
        $breaker->recordSuccess();

        $this->assertFalse($breaker->isOpen());
    }

    public function test_different_names_do_not_share_state(): void
    {
        $breakerA = new CircuitBreaker('supplier-a', failureThreshold: 1, cooldownSeconds: 60);
        $breakerB = new CircuitBreaker('supplier-b', failureThreshold: 1, cooldownSeconds: 60);

        $breakerA->recordFailure();

        $this->assertTrue($breakerA->isOpen());
        $this->assertFalse($breakerB->isOpen());
    }
}
