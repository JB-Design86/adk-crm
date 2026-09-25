<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');
            $table->string('source');
            $table->date('retrieved_at');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->json('skipped_rows')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_normalized')->index();
            $table->string('legal_form')->nullable();
            $table->string('industry')->nullable()->index();
            $table->string('wz_code', 20)->nullable();
            $table->char('priority', 1)->nullable()->index();
            $table->string('street')->nullable();
            $table->string('postal_code', 10)->nullable()->index();
            $table->string('city')->nullable();
            $table->string('phone_e164', 20)->nullable()->index();
            $table->string('phone_display', 40)->nullable();
            $table->string('email')->nullable()->index();
            $table->string('website')->nullable();
            $table->string('website_domain')->nullable()->index();
            $table->unsignedInteger('employee_count')->nullable();
            $table->boolean('is_training_company')->nullable();
            $table->string('source');
            $table->date('retrieved_at');
            for ($level = 1; $level <= 5; $level++) {
                $table->boolean("check_{$level}_passed")->nullable();
            }
            $table->text('check_notes')->nullable();
            $table->foreignId('import_log_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('salutation', 20)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name');
            $table->string('position')->nullable();
            $table->string('phone_e164', 20)->nullable()->index();
            $table->string('phone_display', 40)->nullable();
            $table->string('email')->nullable()->index();
            $table->boolean('is_private')->default(false);
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->date('privacy_notice_sent_at')->nullable();
            $table->date('phone_consent_at')->nullable();
            $table->text('phone_consent_proof')->nullable();
            $table->date('phone_consent_last_used_at')->nullable();
            $table->date('health_consent_at')->nullable();
            $table->text('health_consent_proof')->nullable();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_group', 20)->index();
            $table->string('channel', 30)->index();
            $table->string('status', 30)->default('new')->index();
            $table->string('close_reason', 30)->nullable();
            $table->unsignedTinyInteger('call_attempts')->default(0);
            $table->date('next_action_at')->nullable()->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('cross_selling')->default(false)->index();
            $table->date('cross_selling_follow_up_at')->nullable();
            $table->timestamp('last_contact_at')->nullable();
            $table->timestamp('closed_at')->nullable()->index();
            // Vertragsschluss (Stufe 3). Solange leer, gilt die Löschfrist für Interessenten.
            $table->timestamp('contracted_at')->nullable();
            $table->foreignId('import_log_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->index();
            $table->string('outcome', 30)->nullable()->index();
            $table->string('status_from', 30)->nullable();
            $table->string('status_to', 30)->nullable();
            $table->text('body')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('starts_at')->index();
            $table->string('type', 20);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('blocklist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('phone_e164', 20)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('company_name')->nullable();
            $table->string('company_name_normalized')->nullable()->index();
            $table->string('postal_code', 10)->nullable();
            $table->date('blocked_on');
            $table->string('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocklist_entries');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('import_logs');
    }
};
