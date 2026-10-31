<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-133 decisions 2–11: the two-stage reseller application. One row per
 * applicant across both stages; documents only after the admin sends the
 * stage-2 link. `active_email` carries the email while the application is
 * open and is nulled when it is approved or declined, so the unique index
 * is the last guard of "one active application per email" on every database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_applications', function (Blueprint $table) {
            $table->id();
            $table->string('status', 32)->index();
            $table->string('source', 16)->default('public'); // public | admin (Invite directly)
            $table->string('applicant_type', 16); // company | individual

            // Stage 1 (no document).
            $table->string('owner_name');
            $table->string('business_name')->nullable();
            $table->string('email');
            $table->string('active_email')->nullable()->unique();
            $table->string('phone', 30);
            $table->text('experience')->nullable();
            $table->string('monthly_sales_range', 32)->nullable();
            $table->string('main_games', 500)->nullable();
            $table->string('order_channel', 16)->nullable(); // api | whatsapp | not_sure
            $table->string('heard_from', 255)->nullable();
            $table->string('stage1_notice_version', 16)->nullable();
            $table->timestamp('stage1_consented_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();

            // The one live token: the email-verification link while `email_pending`,
            // the stage-2 link while `invited`. Only its hash is stored.
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();

            // Stage 2.
            $table->string('owner_address', 500)->nullable();
            $table->string('business_address', 500)->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->string('stage2_notice_version', 16)->nullable();
            $table->timestamp('stage2_consented_at')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('reseller_id')->nullable()->unique()->constrained('resellers')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('partner_application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_application_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32); // ssm | ic_front | ic_back | bank_statement
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->timestamps();
        });

        // Short names: MySQL caps an identifier at 64 characters.
        Schema::create('partner_document_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('partner_application_documents')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('viewed_at');
        });

        Schema::table('platform_settings', function (Blueprint $table) {
            $table->boolean('partner_applications_open')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('partner_applications_open');
        });
        Schema::dropIfExists('partner_document_views');
        Schema::dropIfExists('partner_application_documents');
        Schema::dropIfExists('partner_applications');
    }
};
