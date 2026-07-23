<?php

namespace Tests\Feature\Services\Voucher;

use App\Models\Voucher;
use App\Services\Voucher\InvalidVoucherException;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_redeem_decreases_remaining_and_keeps_active_when_balance_left(): void
    {
        $voucher = Voucher::query()->create([
            'code' => 'KRS-TEST-1',
            'customer_email' => 'a@example.com',
            'amount' => 1000,
            'remaining' => 1000,
            'status' => 'active',
            'reason' => 'failed order compensation',
        ]);

        $service = app(VoucherService::class);
        $updated = $service->redeem('KRS-TEST-1', 400);

        $this->assertSame(600, $updated->remaining);
        $this->assertSame('active', $updated->status);
    }

    public function test_redeem_marks_exhausted_when_remaining_hits_zero(): void
    {
        Voucher::query()->create([
            'code' => 'KRS-TEST-2',
            'customer_email' => 'a@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'failed order compensation',
        ]);

        $service = app(VoucherService::class);
        $updated = $service->redeem('KRS-TEST-2', 500);

        $this->assertSame(0, $updated->remaining);
        $this->assertSame('exhausted', $updated->status);
    }

    public function test_rejects_redeem_exceeding_remaining_balance(): void
    {
        Voucher::query()->create([
            'code' => 'KRS-TEST-3',
            'customer_email' => 'a@example.com',
            'amount' => 300,
            'remaining' => 300,
            'status' => 'active',
            'reason' => 'failed order compensation',
        ]);

        $service = app(VoucherService::class);

        $this->expectException(InvalidVoucherException::class);

        $service->redeem('KRS-TEST-3', 400);
    }

    public function test_rejects_redeem_when_voucher_not_active(): void
    {
        Voucher::query()->create([
            'code' => 'KRS-TEST-4',
            'customer_email' => 'a@example.com',
            'amount' => 300,
            'remaining' => 300,
            'status' => 'revoked',
            'reason' => 'failed order compensation',
        ]);

        $service = app(VoucherService::class);

        $this->expectException(InvalidVoucherException::class);

        $service->redeem('KRS-TEST-4', 100);
    }
}
