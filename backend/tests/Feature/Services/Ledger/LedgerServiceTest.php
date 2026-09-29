<?php

namespace Tests\Feature\Services\Ledger;

use App\Services\Ledger\InsufficientBalanceException;
use App\Services\Ledger\LedgerService;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /** 2026-09-29 audit: DB-level backstop — Platform rows have a NULL owner_id, which must still collide. */
    public function test_a_second_order_profit_for_the_same_owner_and_order_is_rejected_by_the_database(): void
    {
        $service = app(LedgerService::class);
        $service->credit('platform', null, 500, 'order_profit', 'order', 1);
        $service->credit('affiliate', 7, 50, 'order_profit', 'order', 1); // different owner, same order: fine

        $this->expectException(UniqueConstraintViolationException::class);

        $service->credit('platform', null, 500, 'order_profit', 'order', 1);
    }

    /** Recurring fees legitimately repeat against the same subscription/membership every cycle. */
    public function test_recurring_fee_types_may_repeat_against_the_same_reference(): void
    {
        $service = app(LedgerService::class);
        $service->credit('platform', null, 1990, 'membership_fee', 'membership', 3);
        $service->credit('platform', null, 1990, 'membership_fee', 'membership', 3);
        $service->credit('affiliate', 2, -5000, 'affiliate_tier_fee', 'affiliate_subscription', 4);
        $service->credit('affiliate', 2, -5000, 'affiliate_tier_fee', 'affiliate_subscription', 4);

        $this->assertSame(3980, $service->balance('platform', null));
        $this->assertSame(-10000, $service->balance('affiliate', 2));
    }

    public function test_withdraw_decreases_balance_when_sufficient(): void
    {
        $service = app(LedgerService::class);
        $service->openAccount('affiliate', 1);
        $service->credit('affiliate', 1, 1000, 'order_profit', 'order', 1);

        $service->withdraw('affiliate', 1, 400, 'withdrawal', 55);

        $this->assertSame(600, $service->balance('affiliate', 1));
    }

    public function test_withdraw_rejects_when_insufficient_balance_and_leaves_balance_unchanged(): void
    {
        $service = app(LedgerService::class);
        $service->openAccount('affiliate', 2);
        $service->credit('affiliate', 2, 300, 'order_profit', 'order', 1);

        try {
            $service->withdraw('affiliate', 2, 400, 'withdrawal', 56);
            $this->fail('Expected InsufficientBalanceException was not thrown.');
        } catch (InsufficientBalanceException) {
            // expected
        }

        $this->assertSame(300, $service->balance('affiliate', 2));
    }
}
