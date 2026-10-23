<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zwei Marken (ADK und JB IT-Design, config adk.brands): Marke am Vorgang, an Vorlage, Postfach und Import,
 * sichtbare Marken am Benutzer, Verknüpfung zweier Vorgänge derselben Firma (Paket).
 * Alles Bestehende gehört zur ADK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('brand', 10)->default('adk')->after('id')->index();
            // Vorgang der anderen Marke zur selben Firma, z. B. Kurs bei der ADK plus Umsetzung durch JB IT-Design.
            $table->foreignId('package_lead_id')->nullable()->after('contact_id')->constrained('leads')->nullOnDelete();
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('brand', 10)->default('adk')->after('id')->index();
        });

        // Je Benutzer ein Postfach je Marke. Erst der neue Index, dann der alte: MariaDB braucht für den
        // Fremdschlüssel auf user_id durchgehend einen Index, der mit user_id beginnt.
        Schema::table('mail_connections', function (Blueprint $table) {
            $table->string('brand', 10)->default('adk')->after('user_id');
            $table->unique(['user_id', 'brand'], 'mail_connections_user_brand_unique');
        });

        Schema::table('mail_connections', function (Blueprint $table) {
            $table->dropUnique('mail_connections_user_id_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            // Liste der Marken, die das Konto sieht; leer = alle.
            $table->json('brands')->nullable()->after('role');
        });

        Schema::table('import_logs', function (Blueprint $table) {
            $table->string('brand', 10)->default('adk')->after('id');
            $table->unsignedInteger('rows_attached')->default(0)->after('rows_imported');
            // Zeilen, deren Firma schon da war (andere Marke): Vorgang an die vorhandene Firma gehängt, mit Ampel.
            $table->json('attached_rows')->nullable()->after('skipped_rows');
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->dropColumn(['brand', 'rows_attached', 'attached_rows']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('brands');
        });

        Schema::table('mail_connections', function (Blueprint $table) {
            $table->unique('user_id', 'mail_connections_user_id_unique');
        });

        Schema::table('mail_connections', function (Blueprint $table) {
            $table->dropUnique('mail_connections_user_brand_unique');
            $table->dropColumn('brand');
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropIndex(['brand']);
            $table->dropColumn('brand');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_lead_id');
            $table->dropIndex(['brand']);
            $table->dropColumn('brand');
        });
    }
};
