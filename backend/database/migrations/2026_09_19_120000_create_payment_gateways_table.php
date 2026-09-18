<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-110 PR-C — encrypted-at-rest, admin-editable payment gateway
     * credentials, mirroring `suppliers.api_config` (ADR-046, SUPP-5)
     * verbatim: `api_config` holds whatever that gateway's own adapter
     * needs (for `chip`: `secret_key`/`brand_id`/`base_url`) as `text`
     * because the Eloquent `encrypted:array` cast produces ciphertext
     * far longer than a varchar. `gateway_key` matches
     * `PaymentGatewayFactory`'s own resolution key ('chip' today; kept
     * multi-row-ready for a future 2nd gateway, ADR-022's own
     * still-open seam).
     */
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('gateway_key')->unique();
            $table->text('api_config'); // encrypted at rest via Eloquent cast, mirrors Supplier.api_config
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
