<?php

namespace Tests\Feature\Services\Supplier;

use App\Jobs\LogSupplierRequestJob;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\Supplier\CircuitBreakingSupplierAdapter;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CircuitBreakingSupplierAdapterTest extends TestCase
{
    /**
     * ADR-051: an open breaker now dispatches LogSupplierRequestJob
     * (ShouldQueue) directly — see GamevionAdapterTest's own copy of
     * this note for why Queue::fake() is needed here.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * Real in-test fake, not a mock - same convention as
     * OrderFulfillmentServiceTest/CheckoutControllerTest: queues up
     * canned responses, records how many times it was actually called.
     */
    private function fakeInner(array $responses): SupplierAdapter
    {
        return new class($responses) implements SupplierAdapter
        {
            public int $calls = 0;

            public function __construct(private array $responses)
            {
            }

            public function checkBalance(): SupplierResponse
            {
                $this->calls++;

                return array_shift($this->responses);
            }

            public function listProducts(): SupplierResponse
            {
                return $this->checkBalance();
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                return $this->checkBalance();
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                return $this->checkBalance();
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('no validator');
            }
        };
    }

    public function test_passes_through_a_successful_call_and_the_circuit_stays_closed(): void
    {
        $inner = $this->fakeInner([SupplierResponse::success(['ok' => true])]);
        $breaker = new CircuitBreaker('test-'.uniqid(), failureThreshold: 3, cooldownSeconds: 60);
        $adapter = new CircuitBreakingSupplierAdapter($inner, $breaker);

        $response = $adapter->checkBalance();

        $this->assertTrue($response->success);
        $this->assertFalse($breaker->isOpen());
    }

    public function test_a_business_rejection_does_not_trip_the_breaker(): void
    {
        $inner = $this->fakeInner([
            SupplierResponse::failure('422', 'invalid product code'),
            SupplierResponse::failure('422', 'invalid product code'),
            SupplierResponse::failure('422', 'invalid product code'),
        ]);
        $breaker = new CircuitBreaker('test-'.uniqid(), failureThreshold: 3, cooldownSeconds: 60);
        $adapter = new CircuitBreakingSupplierAdapter($inner, $breaker);

        $adapter->checkBalance();
        $adapter->checkBalance();
        $adapter->checkBalance();

        $this->assertFalse($breaker->isOpen());
    }

    public function test_repeated_server_errors_trip_the_breaker(): void
    {
        $inner = $this->fakeInner([
            SupplierResponse::failure('500', 'server error', isServerError: true),
            SupplierResponse::failure('500', 'server error', isServerError: true),
            SupplierResponse::failure('500', 'server error', isServerError: true),
        ]);
        $breaker = new CircuitBreaker('test-'.uniqid(), failureThreshold: 3, cooldownSeconds: 60);
        $adapter = new CircuitBreakingSupplierAdapter($inner, $breaker);

        $adapter->checkBalance();
        $adapter->checkBalance();
        $adapter->checkBalance();

        $this->assertTrue($breaker->isOpen());
    }

    public function test_an_open_circuit_short_circuits_without_calling_the_inner_adapter(): void
    {
        $inner = $this->fakeInner([
            SupplierResponse::failure('500', 'server error', isServerError: true),
            SupplierResponse::success(['ok' => true]),
        ]);
        $breaker = new CircuitBreaker('test-'.uniqid(), failureThreshold: 1, cooldownSeconds: 60);
        $adapter = new CircuitBreakingSupplierAdapter($inner, $breaker);

        $adapter->checkBalance();
        $this->assertTrue($breaker->isOpen());

        $response = $adapter->checkBalance();

        $this->assertFalse($response->success);
        $this->assertSame('CIRCUIT_OPEN', $response->errorCode);
        $this->assertSame(1, $inner->calls);

        // ADR-051 decision 3 — the skipped call still gets a synthetic
        // request-log row, distinct from a real HTTP failure.
        Queue::assertPushed(LogSupplierRequestJob::class, fn ($job) => $job->entry()['slug'] === $breaker->name()
            && $job->entry()['call_type'] === 'checkBalance'
            && $job->entry()['outcome'] === 'skipped_breaker_open');
    }

    public function test_recovers_after_the_cooldown_elapses(): void
    {
        $inner = $this->fakeInner([
            SupplierResponse::failure('500', 'server error', isServerError: true),
            SupplierResponse::success(['ok' => true]),
        ]);
        $breaker = new CircuitBreaker('test-'.uniqid(), failureThreshold: 1, cooldownSeconds: 30);
        $adapter = new CircuitBreakingSupplierAdapter($inner, $breaker);

        $adapter->checkBalance();
        $this->assertTrue($breaker->isOpen());

        $this->travel(31)->seconds();

        $response = $adapter->checkBalance();

        $this->assertTrue($response->success);
        $this->assertFalse($breaker->isOpen());
    }

    public function test_validate_player_is_never_guarded_by_the_breaker(): void
    {
        $inner = $this->fakeInner([]);
        $breaker = new CircuitBreaker('test-'.uniqid(), failureThreshold: 1, cooldownSeconds: 60);
        $adapter = new CircuitBreakingSupplierAdapter($inner, $breaker);

        $this->expectException(ValidationNotSupportedException::class);

        $adapter->validatePlayer('123', null);
    }
}
