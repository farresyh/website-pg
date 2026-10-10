<?php

use App\Services\Report\ReportService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-083 2026-10-10 addendum, decision 15: the day the money left the
     * bank, from the transfer receipt — month assignment and the supplier
     * as-of balance read this, never the row's own `created_at` (the day it
     * was typed in). Existing rows take their `created_at` as a KL date.
     *
     * Decision 7: the `PaidFrom` director value `luqman` is renamed `lokman`.
     */
    public function up(): void
    {
        Schema::table('supplier_transfers', function (Blueprint $table) {
            $table->date('transferred_on')->nullable()->after('supplier_id');
        });

        DB::table('supplier_transfers')->orderBy('id')->each(function (object $row) {
            DB::table('supplier_transfers')->where('id', $row->id)->update([
                'transferred_on' => Carbon::parse($row->created_at, 'UTC')->setTimezone(ReportService::TIMEZONE)->toDateString(),
            ]);
        });

        Schema::table('supplier_transfers', function (Blueprint $table) {
            $table->date('transferred_on')->nullable(false)->change();
            $table->index('transferred_on');
        });

        DB::table('supplier_transfers')->where('paid_by', 'luqman')->update(['paid_by' => 'lokman']);
    }

    public function down(): void
    {
        DB::table('supplier_transfers')->where('paid_by', 'lokman')->update(['paid_by' => 'luqman']);

        Schema::table('supplier_transfers', function (Blueprint $table) {
            $table->dropIndex(['transferred_on']);
            $table->dropColumn('transferred_on');
        });
    }
};
