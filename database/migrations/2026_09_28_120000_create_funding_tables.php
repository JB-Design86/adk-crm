<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stufe 3 · Förderweg: Schritte je Förderweg (pflegbar), Förderfälle und erledigte Schritte.
 * Die Standard-Schritte folgen dem Lastenheft, Abschnitt 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_steps', function (Blueprint $table) {
            $table->id();
            $table->string('pathway', 30)->index();
            $table->string('name');
            $table->text('instructions')->nullable();
            $table->string('default_party', 20)->nullable();
            $table->unsignedSmallInteger('follow_up_days')->default(5);
            $table->boolean('calendar_days')->default(false);
            $table->boolean('can_fail')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('funding_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('pathway', 30)->index();
            $table->string('state', 20)->default('open')->index();
            $table->string('customer_number')->nullable();
            $table->string('voucher_number')->nullable();
            $table->date('voucher_valid_until')->nullable();
            $table->string('funder_contact')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('funding_case_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funding_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('funding_step_id')->constrained()->cascadeOnDelete();
            $table->date('completed_on');
            $table->string('result', 20)->default('done');
            $table->string('party', 20)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['funding_case_id', 'funding_step_id']);
        });

        $todo = 'Erklärtext folgt: Was ist jetzt von unserer Seite zu tun?';
        $steps = [
            'voucher' => [
                ['Beratungsgespräch geführt', 'adk', 2, false, false],
                ['Eignung festgestellt (A-06)', 'adk', 2, false, false],
                ['Informationsblatt A-26 versendet', 'adk', 5, false, false],
                ['Termin bei der Vermittlungsfachkraft', 'person', 5, false, false],
                ['Bildungsgutschein beantragt', 'person', 10, false, false],
                ['Bildungsgutschein bewilligt', 'funder', 5, false, true],
                ['Bildungsgutschein eingelöst', 'person', 5, false, false],
                ['Vertrag geschlossen', 'adk', 5, false, false],
                ['Anmeldung vom Kostenträger bestätigt', 'funder', 5, false, false],
            ],
            'employer' => [
                ['Gespräch mit dem Betrieb', 'adk', 3, false, false],
                ['Angebot versendet', 'adk', 5, false, false],
                ['Antrag beim Arbeitgeber-Service gestellt', 'company', 10, false, false],
                ['Bewilligung erhalten', 'funder', 5, false, true],
                ['Vertrag geschlossen', 'adk', 5, false, false],
                ['Anmeldung vom Kostenträger bestätigt', 'funder', 5, false, false],
            ],
            'accident' => [
                ['Sachverhalt geklärt', 'adk', 2, false, false],
                ['Formloser Antrag gestellt', 'person', 14, true, false],
                ['Zuständigkeit geklärt (§ 14 SGB IX: 2 Wochen)', 'funder', 21, true, false],
                ['Ärztliche Bescheinigung liegt vor (nur Datum, kein Inhalt)', 'person', 5, false, false],
                ['Kontakt Reha-Management (schriftliche Einwilligung liegt vor)', 'adk', 5, false, false],
                ['Maßnahmenpaket versendet', 'adk', 10, false, false],
                ['Kostenzusage erhalten (§ 14 SGB IX: Entscheidung binnen 3 Wochen)', 'funder', 5, false, true],
                ['Vertrag geschlossen', 'adk', 5, false, false],
                ['Anmeldung vom Kostenträger bestätigt', 'funder', 5, false, false],
            ],
            'self_payer' => [
                ['Angebot mit Ratenplan versendet', 'adk', 5, false, false],
                ['Vertrag geschlossen', 'adk', 5, false, false],
            ],
        ];

        $now = now();

        foreach ($steps as $pathway => $list) {
            foreach ($list as $index => [$name, $party, $days, $calendar, $canFail]) {
                DB::table('funding_steps')->insert([
                    'pathway' => $pathway,
                    'name' => $name,
                    'instructions' => $todo,
                    'default_party' => $party,
                    'follow_up_days' => $days,
                    'calendar_days' => $calendar,
                    'can_fail' => $canFail,
                    'sort_order' => $index + 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Bestehende Vorgänge „Übergeben an Förderweg“ (vor Stufe 3) bekommen einen Förderfall.
        // Betriebe mit offener Zielgruppe laufen über den Förderweg für Betriebe (C).
        $pathwayFor = function (string $targetGroup): string {
            foreach (config('adk.funding_pathways') as $key => $pathway) {
                if (in_array($targetGroup, $pathway['target_groups'], true)) {
                    return $key;
                }
            }

            return 'employer';
        };

        DB::table('leads')->where('status', 'handed_over')->orderBy('id')->each(function ($lead) use ($pathwayFor, $now) {
            if ($lead->target_group === 'company_open') {
                DB::table('leads')->where('id', $lead->id)->update(['target_group' => 'C']);
            }

            DB::table('funding_cases')->insert([
                'lead_id' => $lead->id,
                'pathway' => $pathwayFor($lead->target_group === 'company_open' ? 'C' : $lead->target_group),
                'state' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($lead->next_action_at === null) {
                DB::table('leads')->where('id', $lead->id)->update(['next_action_at' => $now->toDateString()]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_case_steps');
        Schema::dropIfExists('funding_cases');
        Schema::dropIfExists('funding_steps');
    }
};
