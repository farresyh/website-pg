<?php

namespace Tests\Feature\Services\Ledger;

use App\Services\Ledger\InsufficientBalanceException;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_account_starts_at_zero_balance(): void
    {
        $service = app(LedgerService::class);
        $service->openAccount('platform', null);

        $this->assertSame(0, $service->balance('platform', null));
    }

    public function test_credit_increases_balance(): void
    {
        $service = app(LedgerService::class);
        $service->openAccount('platform', null);

        $service->credit('platform', null, 500, 'order_profit', 'order', 1);
        $service->credit('platform', null, 300, 'order_profit', 'order', 2);

        $this->assertSame(800, $service->balance('platform', null));
    }

    public function test_withdraw_decreases_balance_when_sufficient(): void
    {
        $service = app(LedgerService::class);
        $service->openAccount('reseller', 1);
        $service->credit('reseller', 1, 1000, 'order_profit', 'order', 1);

        $service->withdraw('reseller', 1, 400, 'withdrawal', 55);

        $this->assertSame(600, $service->balance('reseller', 1));
    }

    public function test_withdraw_rejects_when_insufficient_balance_and_leaves_balance_unchanged(): void
    {
        $service = app(LedgerService::class);
        $service->openAccount('reseller', 2);
        $service->credit('reseller', 2, 300, 'order_profit', 'order', 1);

        try {
            $service->withdraw('reseller', 2, 400, 'withdrawal', 56);
            $this->fail('Expected InsufficientBalanceException was not thrown.');
        } catch (InsufficientBalanceException) {
            // expected
        }

        $this->assertSame(300, $service->balance('reseller', 2));
    }
}
