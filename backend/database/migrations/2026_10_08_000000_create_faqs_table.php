<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-120 decision 13: FAQ moves from the storefront's hardcoded
 * `placeholder-data.ts` into admin-central content — one platform set,
 * `{store_name}` substituted per brand at read time. Seeded with the
 * exact five items the storefront showed, so the homepage is unchanged
 * on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->text('answer');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('faqs')->insert(array_map(fn (array $faq, int $i) => [
            'question' => $faq[0],
            'answer' => $faq[1],
            'sort_order' => $i,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['How long does the top-up process take?', 'Most orders are processed automatically within 1–3 minutes after payment is confirmed by our system.'],
            ['How do I check my order status?', 'Use the "Track Order" menu at the top of the site and enter your order number.'],
            ['What should I do if I entered the wrong ID?', 'Contact our WhatsApp customer support immediately with your order number. Successful delivery to a wrong ID cannot be reversed.'],
            ['Is my payment secure?', 'Yes. All transactions are processed through licensed FPX and e-wallet networks with SSL encryption.'],
            ['How do I contact support?', 'Reach our customer support team directly via WhatsApp for a manual check if your status is delayed beyond 10 minutes.'],
        ], range(1, 5)));
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
