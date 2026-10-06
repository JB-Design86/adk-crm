<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website-Eingang (Kontaktformular, Kursheft-Anforderung).
 *
 * - contacts.phone_refused: Person möchte nicht angerufen werden (im Formular kein Rückruf erlaubt).
 * - contacts.email_consent_*: Einwilligung in die Kontaktaufnahme per E-Mail mit Nachweis (Double-Opt-in).
 * - leads.intake_ref: Nummer des Vorgangs auf der Website, z. B. „kontakt-17“. Eindeutig, damit eine
 *   wiederholte Übertragung keinen zweiten Vorgang anlegt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('phone_refused')->default(false)->after('phone_consent_last_used_at');
            $table->date('email_consent_at')->nullable()->after('phone_refused');
            $table->text('email_consent_proof')->nullable()->after('email_consent_at');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('intake_ref', 40)->nullable()->after('import_log_id');
            $table->unique('intake_ref', 'leads_intake_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique('leads_intake_ref_unique');
            $table->dropColumn('intake_ref');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['phone_refused', 'email_consent_at', 'email_consent_proof']);
        });
    }
};
