<?php

namespace Tests\Feature\Database;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CHIP's code for DuitNow QR is `dnqr` (2026-10-28). The row and the
 * attempts that carry the old guess are renamed once; a second run changes nothing.
 */
class RenameDuitnowQrChannelCodeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_renames_the_row_keeps_its_state_and_is_idempotent(): void
    {
        PaymentMethod::query()->where('channel_code', 'dnqr')->delete();
        PaymentMethod::query()->create([
            'channel_code' => 'duitnow_qr', 'method_key' => 'duitnow_qr_chip', 'label' => 'DuitNow QR',
            'category' => 'duitnow_qr', 'gateway' => 'chip', 'is_active' => true, 'percentage_rate' => 0.25, 'flat_fee_sen' => 0,
        ]);

        $migration = require database_path('migrations/2026_10_28_120000_rename_duitnow_qr_channel_code_to_dnqr.php');
        $migration->up();
        $migration->up();

        $row = PaymentMethod::query()->where('channel_code', 'dnqr')->firstOrFail();
        $this->assertTrue($row->is_active);
        $this->assertSame(0, DB::table('payment_methods')->where('channel_code', 'duitnow_qr')->count());
    }
}
