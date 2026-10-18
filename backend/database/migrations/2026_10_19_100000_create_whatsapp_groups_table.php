<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-125 decision 11: one WhatsApp group registry for resellers and
 * suppliers, replacing `reseller_whatsapp_groups` (its rows are copied
 * as owner_type = reseller, ids kept). The pending-link list is owner
 * neutral already, so its table is only renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_groups', function (Blueprint $table) {
            $table->id();
            $table->string('whatsapp_group_id')->unique();
            $table->string('owner_type', 16);
            $table->unsignedBigInteger('owner_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });

        DB::table('reseller_whatsapp_groups')->orderBy('id')->each(function (object $row) {
            DB::table('whatsapp_groups')->insert([
                'id' => $row->id,
                'whatsapp_group_id' => $row->whatsapp_group_id,
                'owner_type' => 'reseller',
                'owner_id' => $row->reseller_id,
                'is_active' => $row->is_active,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });

        Schema::drop('reseller_whatsapp_groups');
        Schema::rename('reseller_whatsapp_pending_links', 'whatsapp_pending_links');
    }

    public function down(): void
    {
        Schema::rename('whatsapp_pending_links', 'reseller_whatsapp_pending_links');

        Schema::create('reseller_whatsapp_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('resellers')->cascadeOnDelete();
            $table->string('whatsapp_group_id')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('whatsapp_groups')->where('owner_type', 'reseller')->orderBy('id')->each(function (object $row) {
            DB::table('reseller_whatsapp_groups')->insert([
                'id' => $row->id,
                'reseller_id' => $row->owner_id,
                'whatsapp_group_id' => $row->whatsapp_group_id,
                'is_active' => $row->is_active,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });

        Schema::drop('whatsapp_groups');
    }
};
