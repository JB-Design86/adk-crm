<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stufe 4, erste Fassung: Teilnehmerakte mit Checkliste (Lastenheft 7.1) und
 * Dokumente am Vorgang, Förderfall und an der Akte (Lastenheft 7.2), verschlüsselt.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Förderweg-Schritte: erwartete Unterlage, Pflicht vor der Einschreibung.
        Schema::table('funding_steps', function (Blueprint $table) {
            $table->string('document_category', 40)->nullable()->after('can_fail');
            $table->boolean('requires_document')->default(false)->after('document_category');
        });

        // Betrieb mit Vertrag (§ 82 SGB III): Firmenkunde seit.
        Schema::table('organizations', function (Blueprint $table) {
            $table->date('customer_since')->nullable()->after('check_notes');
        });

        $documents = [
            'Eignung festgestellt (A-06)' => ['aptitude', false],
            'Informationsblatt A-26 versendet' => ['info_sheet', false],
            'Bildungsgutschein beantragt' => ['application', false],
            'Bildungsgutschein bewilligt' => ['funding_voucher', true],
            'Angebot versendet' => ['offer', false],
            'Antrag beim Arbeitgeber-Service gestellt' => ['application', false],
            'Bewilligung erhalten' => ['funding_approval', true],
            'Formloser Antrag gestellt' => ['application', false],
            'Maßnahmenpaket versendet' => ['offer', false],
            'Kostenzusage erhalten (§ 14 SGB IX: Entscheidung binnen 3 Wochen)' => ['funding_approval', true],
            'Angebot mit Ratenplan versendet' => ['offer', false],
            'Vertrag geschlossen' => ['contract', true],
            'Anmeldung vom Kostenträger bestätigt' => ['funder_correspondence', false],
        ];

        foreach ($documents as $name => [$category, $required]) {
            DB::table('funding_steps')->where('name', $name)->update(['document_category' => $category, 'requires_document' => $required]);
        }

        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('funding_case_id')->nullable()->constrained()->nullOnDelete();
            // Name, Telefon und E-Mail stehen am Kontakt (eine Datenquelle), hier nur das, was für Vertrag und Gutschein dazukommt.
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->date('birth_date')->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city')->nullable();
            $table->string('course_name')->nullable();
            $table->date('course_starts_on')->nullable();
            $table->date('course_ends_on')->nullable();
            $table->string('state', 20)->default('registered')->index();
            $table->date('left_on')->nullable();
            $table->string('exit_reason')->nullable();
            $table->date('follow_up_on')->nullable()->index();
            $table->string('placement_status', 30)->nullable();
            $table->date('placement_recorded_on')->nullable();
            // Nachteilsausgleich: Gesundheitsbezug, verschlüsselt, nur für die Verwaltung sichtbar.
            $table->text('accommodation_notes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category', 40)->index();
            $table->string('title');
            $table->string('original_name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->string('path')->unique();
            $table->char('sha256', 64);
            $table->date('document_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('participant_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->nullable()->unique();
            $table->string('phase', 20)->index();
            $table->string('name');
            $table->text('instructions')->nullable();
            $table->string('document_category', 40)->nullable();
            $table->boolean('repeatable')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('participant_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_checklist_item_id')->constrained()->cascadeOnDelete();
            $table->date('done_on');
            $table->text('note')->nullable();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['participant_id', 'participant_checklist_item_id']);
        });

        // Checkliste laut Lastenheft 7.1, Phasen 5 bis 9 des Teilnehmerwegs.
        $items = [
            'contract' => [
                ['contract', 'Schulungsvertrag A-16 unterschrieben', 'contract'],
                ['privacy', 'Datenschutzhinweis A-23 bestätigt', 'privacy_notice'],
                ['placement_consent', 'Einwilligung Verbleibserhebung', 'consent'],
                ['device_handover', 'Leihgerät übergeben (Übergabeprotokoll)', 'device_handover'],
                ['funder_confirmed', 'Anmeldung beim Kostenträger bestätigt', 'funder_correspondence'],
            ],
            'entry' => [
                ['entry_report', 'Eintrittsmeldung an den Kostenträger', 'funder_correspondence'],
                ['platform_access', 'Zugang Lernplattform eingerichtet', null],
                ['tech_check', 'Technikprobe durchgeführt', null],
            ],
            'delivery' => [
                ['interview_1', 'Zwischengespräch Fachteil 1', 'protocol'],
                ['survey_1', 'Zwischenbefragung Fachteil 1', 'protocol'],
                ['interview_2', 'Zwischengespräch Fachteil 2', 'protocol'],
                ['survey_2', 'Zwischenbefragung Fachteil 2', 'protocol'],
                ['absence', 'Fehlzeitenmeldung', 'absence', true],
            ],
            'completion' => [
                ['final_assessment', 'Abschlussleistung A-08 bewertet', 'assessment'],
                ['certificate', 'Zertifikat bzw. Teilnahmebescheinigung A-09 ausgestellt', 'certificate'],
                ['final_survey', 'Abschlussbefragung A-17', null],
                ['exit_report', 'Austrittsmeldung an den Kostenträger', 'funder_correspondence'],
                ['device_return', 'Leihgerät zurückgegeben', 'device_handover'],
                ['access_revoked', 'Zugänge gesperrt', null],
            ],
            'follow_up' => [
                ['placement_survey', 'Nachbefragung zum Verbleib versendet', null],
            ],
        ];

        $sort = 0;
        $now = now();

        foreach ($items as $phase => $list) {
            foreach ($list as $item) {
                DB::table('participant_checklist_items')->insert([
                    'key' => $item[0],
                    'phase' => $phase,
                    'name' => $item[1],
                    'document_category' => $item[2],
                    'repeatable' => $item[3] ?? false,
                    'sort_order' => ++$sort,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_checks');
        Schema::dropIfExists('participant_checklist_items');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('participants');

        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('customer_since'));
        Schema::table('funding_steps', fn (Blueprint $table) => $table->dropColumn(['document_category', 'requires_document']));
    }
};
