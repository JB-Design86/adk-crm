<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\BlocklistEntry;
use App\Models\Contact;
use App\Models\FundingCase;
use App\Models\ImportLog;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\ParticipantChecklistItem;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\FundingService;
use App\Services\LeadStatusService;
use App\Services\ParticipantService;
use App\Support\Adk;
use App\Support\WorkingDays;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Erfundene Testdaten für Entwicklung und crm-test.adk-akademie.de.
 * Läuft in der Betriebsumgebung (APP_ENV=production) nie.
 *
 * - 2 Testkonten (Zugangsdaten in docs/BETRIEB.md, nur Testumgebung)
 * - rund 300 Betriebe aus Rhein-Main in 10 Branchen, Priorität A/B/C
 * - 30 Privatpersonen aus eingehenden Kanälen
 * - Vorgänge in allen Status, Aktivitäten und Termine über die letzten vier Wochen
 * - 5 Sperrlisteneinträge
 */
class TestDataSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'verwaltung@crm-test.example';

    public const ADMIN_PASSWORD = 'Test-Verwaltung-2026';

    public const STAFF_EMAIL = 'mitarbeit@crm-test.example';

    public const STAFF_PASSWORD = 'Test-Mitarbeit-2026';

    private LeadStatusService $service;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Testdaten werden in der Betriebsumgebung nicht eingespielt (APP_ENV=production).');
        }

        fake()->seed(20260925);
        $this->service = app(LeadStatusService::class);
        $realNow = CarbonImmutable::now();

        $admin = User::create(['name' => 'Test Verwaltung', 'email' => self::ADMIN_EMAIL, 'role' => 'admin', 'password' => self::ADMIN_PASSWORD]);
        $staff = User::create(['name' => 'Test Mitarbeit', 'email' => self::STAFF_EMAIL, 'role' => 'staff', 'password' => self::STAFF_PASSWORD]);
        $users = collect([$admin, $staff]);

        $start = $realNow->subWeeks(4)->startOfWeek();

        $this->at($start->setTime(8, 0), function () use ($admin) {
            $this->seedCompanies($admin);
            $this->seedBlocklist($admin);
        });

        $this->seedPrivatePersons($users, $start, $realNow);
        $this->simulateCalling($users, $start, $realNow);

        $this->at($realNow, function () use ($admin, $staff) {
            $this->ensureAllStatuses($staff);
            $this->seedFundingProgress($staff);
            $this->topUpBlocklist($admin);
            $this->seedTodaysAppointment($staff);
        });

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        $this->command?->info('Testdaten: '.Organization::count().' Organisationen, '.Lead::count().' Vorgänge, '.Activity::count().' Aktivitäten, '.BlocklistEntry::count().' Sperrlisteneinträge.');
        $this->command?->info('Testkonten: '.self::ADMIN_EMAIL.' / '.self::ADMIN_PASSWORD.' (Verwaltung), '.self::STAFF_EMAIL.' / '.self::STAFF_PASSWORD.' (Mitarbeitende).');
    }

    private function seedCompanies(User $admin): void
    {
        $sources = [
            'Leadliste Rhein-Main 2026-09 (Test)' => 180,
            'IHK-Firmenverzeichnis (Test)' => 80,
            'Branchenbuch (Test)' => 40,
        ];

        foreach ($sources as $source => $count) {
            $log = ImportLog::create([
                'file_name' => str($source)->slug().'.xlsx',
                'source' => $source,
                'retrieved_at' => today()->subDays(3),
                'rows_total' => $count + 4,
                'rows_imported' => $count,
                'rows_skipped' => 4,
                'duplicates' => 3,
                'skipped_rows' => [
                    ['row' => 12, 'name' => 'Beispiel GmbH', 'reason' => 'Dublette in der Datei: gleiche Telefonnummer wie Zeile 8', 'type' => 'duplicate'],
                    ['row' => 40, 'name' => 'Muster KG', 'reason' => 'Dublette: gleiche Website wie Organisation 17 (Muster KG)', 'type' => 'duplicate'],
                    ['row' => 77, 'name' => 'Test AG', 'reason' => 'Dublette: gleicher Firmenname und gleiche PLZ wie Organisation 51 (Test AG)', 'type' => 'duplicate'],
                    ['row' => 95, 'name' => '', 'reason' => 'Firmenname fehlt', 'type' => 'invalid'],
                ],
                'user_id' => $admin->id,
            ]);

            Organization::factory()
                ->withChecks()
                ->count($count)
                ->state(['source' => $source, 'retrieved_at' => today()->subDays(3), 'import_log_id' => $log->id])
                ->create()
                ->each(function (Organization $organization) use ($log) {
                    $contact = fake()->boolean(55)
                        ? Contact::factory()->create(['organization_id' => $organization->id])
                        : null;

                    Lead::create([
                        'organization_id' => $organization->id,
                        'contact_id' => $contact?->id,
                        'target_group' => 'company_open',
                        'channel' => 'cold_call',
                        'import_log_id' => $log->id,
                    ]);
                });
        }
    }

    private function seedBlocklist(User $admin): void
    {
        $entries = [
            ['phone_e164' => '+496131999001', 'reason' => 'Werbewiderspruch telefonisch (Test)'],
            ['email' => 'keine-werbung@beispiel-firma.example', 'reason' => 'Werbewiderspruch per E-Mail (Test)'],
            ['company_name' => 'Gesperrt Beispiel GmbH', 'postal_code' => '55116', 'reason' => 'Werbewiderspruch schriftlich (Test)'],
        ];

        foreach ($entries as $entry) {
            BlocklistEntry::create([...$entry, 'created_by' => $admin->id, 'blocked_on' => today()]);
        }
    }

    /**
     * Jeder Status kommt mindestens einmal vor, unabhängig vom Zufall der Simulation.
     * Werbewiderspruch nur bei Betrieben ohne Kontakt (ein Sperrlisteneintrag).
     */
    private function ensureAllStatuses(User $user): void
    {
        foreach (array_keys(config('adk.statuses')) as $status) {
            if ($status === 'new' || (config("adk.statuses.{$status}.manual") ?? true) === false || Lead::where('status', $status)->exists()) {
                continue;
            }

            $lead = Lead::query()
                ->whereNull('closed_at')
                ->where('status', 'new')
                ->whereNotNull('organization_id')
                ->when($status === 'objection', fn ($q) => $q->whereNull('contact_id'))
                ->whereDoesntHave('organization', fn ($q) => $q->whereIn('phone_e164', BlocklistEntry::whereNotNull('phone_e164')->pluck('phone_e164')))
                ->first();

            if (! $lead) {
                continue;
            }

            $lead->forceFill(['assigned_to' => $user->id])->saveQuietly();
            $this->service->apply($lead, $status, [
                ...$this->dataFor($status),
                'contact' => ['last_name' => fake()->lastName()],
            ], $user, asCall: $status !== 'handed_over');
        }
    }

    /**
     * Förderfälle mit unterschiedlichem Fortschritt; einer ist bereits eingeschrieben.
     */
    private function seedFundingProgress(User $user): void
    {
        $funding = app(FundingService::class);
        $cases = FundingCase::query()->open()->with('lead')->orderBy('id')->get();

        foreach ($cases as $index => $case) {
            $steps = $case->steps();
            $target = $index === 0 ? $steps->count() : fake()->numberBetween(0, max(0, $steps->count() - 2));
            $date = CarbonImmutable::parse($case->created_at)->startOfDay();

            foreach ($steps->take($target) as $step) {
                $date = min($date->addDays(fake()->numberBetween(1, 3)), CarbonImmutable::today());
                $funding->completeStep($case, $step, [
                    'completed_on' => $date->toDateString(),
                    'party' => $step->default_party,
                    'note' => fake()->randomElement([null, 'telefonisch abgestimmt', 'Unterlagen per E-Mail erhalten']),
                ], $user);
            }

            if ($index === 0 && $case->fresh()->allStepsDone()) {
                $case->update(['customer_number' => '123D456789', 'voucher_number' => 'BGS-TEST-0001', 'voucher_valid_until' => today()->addMonths(2)]);

                // Pflichtunterlagen als erkennbar fiktive PDFs, damit die Einschreibung möglich ist.
                foreach (array_keys($case->missingDocuments()) as $category) {
                    app(DocumentService::class)->store($case->lead, $this->testPdf(Adk::documentCategoryLabel($category)), [
                        'category' => $category,
                        'title' => Adk::documentCategoryLabel($category).' (Testdokument)',
                        'document_date' => today()->subDays(3)->toDateString(),
                    ], user: $user);
                }

                $participant = $funding->enroll($case->fresh(), $user, [
                    'course_name' => 'KI-Kompetenz für den Beruf (Testkurs)',
                    'course_starts_on' => today()->addWeeks(2)->toDateString(),
                    'course_ends_on' => today()->addWeeks(14)->toDateString(),
                    'birth_date' => '1985-04-12',
                    'street' => 'Musterstraße 1',
                    'postal_code' => '55116',
                    'city' => 'Mainz',
                ]);

                // Erste Punkte der Checkliste, damit die Akte nicht leer ist.
                foreach (['privacy', 'placement_consent'] as $key) {
                    if ($participant && ($item = ParticipantChecklistItem::where('key', $key)->first())) {
                        app(ParticipantService::class)->completeCheck($participant, $item, ['done_on' => today()->toDateString(), 'note' => 'Testdaten'], $user);
                    }
                }
            }
        }
    }

    /** Kleines PDF mit deutlichem Hinweis „Testdokument“. */
    private function testPdf(string $title): UploadedFile
    {
        $pdf = new Dompdf;
        $pdf->loadHtml('<h1 style="font-family: DejaVu Sans">TESTDOKUMENT</h1><p style="font-family: DejaVu Sans">'.e($title).'</p><p style="font-family: DejaVu Sans">Fiktive Daten für crm-test. Kein echtes Dokument.</p>', 'UTF-8');
        $pdf->render();

        $path = tempnam(sys_get_temp_dir(), 'test').'.pdf';
        file_put_contents($path, $pdf->output());

        return new UploadedFile($path, Str::slug($title).'.pdf', 'application/pdf', null, true);
    }

    /** Sperrliste auf genau 5 Einträge auffüllen (Taste 8 in der Simulation erzeugt bis zu 2). */
    private function topUpBlocklist(User $admin): void
    {
        $extra = [
            ['phone_e164' => '+49611999002', 'reason' => 'Werbewiderspruch telefonisch (Test)'],
            ['email' => 'bitte-nicht@beispiel-kanzlei.example', 'reason' => 'Werbewiderspruch per E-Mail (Test)'],
        ];

        foreach ($extra as $entry) {
            if (BlocklistEntry::count() >= 5) {
                break;
            }

            BlocklistEntry::create([...$entry, 'created_by' => $admin->id, 'blocked_on' => today()]);
        }
    }

    /** Ein Termin am heutigen Tag, damit „Heute“ und Kalender ihn zeigen. */
    private function seedTodaysAppointment(User $staff): void
    {
        $lead = Lead::whereNull('closed_at')->whereNotNull('organization_id')->where('status', 'interested')->first();

        if ($lead) {
            $this->service->apply($lead, 'appointment', [
                'appointment' => ['date' => today()->toDateString(), 'time' => '15:00', 'type' => 'teams', 'user_id' => $staff->id],
                'note' => 'Termin für heute vereinbart',
            ], $staff);
        }
    }

    /** 30 Privatpersonen aus eingehenden Kanälen, über vier Wochen verteilt. */
    private function seedPrivatePersons(Collection $users, CarbonImmutable $start, CarbonImmutable $now): void
    {
        $channels = ['website_form', 'email_info', 'phone', 'whatsapp', 'calendly', 'kursnet', 'referral_agent', 'network', 'google_ads', 'social_media', 'chatgpt_ad', 'other'];

        for ($i = 0; $i < 30; $i++) {
            // Die letzten sechs Anfragen kommen heute und bleiben „Neu“ (rot oben in „Heute“).
            $createdAt = $i >= 24
                ? $now->setTime(fake()->numberBetween(7, max(7, min(17, $now->hour))), fake()->numberBetween(0, 59))
                : CarbonImmutable::instance(fake()->dateTimeBetween($start, $now->subDay()))->setTime(fake()->numberBetween(8, 17), fake()->numberBetween(0, 59));

            $this->at($createdAt, function () use ($channels, $users, $i) {
                $targetGroup = fake()->randomElement(['A', 'A', 'B', 'B', 'self_payer', 'D']);
                $contact = Contact::factory()->private()->create([
                    'phone_consent_at' => fake()->boolean(40) ? today() : null,
                    'phone_consent_proof' => null,
                    'health_consent_at' => $targetGroup === 'E' && fake()->boolean(50) ? today() : null,
                ]);

                if ($contact->phone_consent_at) {
                    $contact->update(['phone_consent_proof' => 'Website-Formular, Häkchen Rückrufwunsch (Testdaten)']);
                }

                if ($contact->health_consent_at) {
                    $contact->update(['health_consent_proof' => 'Einwilligung im Erstgespräch, Formular unterschrieben (Testdaten)']);
                }

                $user = $users->random();

                $lead = Lead::create([
                    'contact_id' => $contact->id,
                    'target_group' => $targetGroup,
                    'channel' => $channels[$i % count($channels)],
                    'assigned_to' => $user->id,
                ]);

                // Ältere Anfragen wurden noch am selben Tag zurückgerufen.
                if ($i < 24) {
                    $this->at(CarbonImmutable::now()->addHour(), function () use ($lead, $user) {
                        $outcome = fake()->randomElement(['interested', 'documents_sent', 'appointment', 'later', 'not_reached', 'no_interest', 'handed_over']);

                        try {
                            $this->service->apply($lead, $outcome, [
                                ...$this->dataFor($outcome),
                                'target_group' => $outcome === 'handed_over' ? $lead->target_group : null,
                                'contact' => null,
                            ], $user, asCall: $outcome !== 'handed_over');
                        } catch (ValidationException) {
                            // z. B. Zielgruppe E ohne Einwilligung Gesundheitsangaben: kein Förderweg.
                            $this->service->apply($lead, 'interested', $this->dataFor('interested'), $user, asCall: true);
                        }
                    });
                }
            });
        }
    }

    /**
     * Simuliert vier Wochen Telefonakquise über den LeadStatusService,
     * damit Aktivitäten, Wiedervorlagen und Termine den Regeln entsprechen.
     */
    private function simulateCalling(Collection $users, CarbonImmutable $start, CarbonImmutable $now): void
    {
        $outcomes = [
            'not_reached' => 34, 'interested' => 12, 'documents_sent' => 14, 'appointment' => 8,
            'later' => 8, 'no_interest' => 12, 'no_need' => 5, 'wrong_data' => 4, 'objection' => 1,
            'handed_over' => 1, 'cross_selling' => 4,
        ];
        $objections = 0;

        for ($day = $start; $day->lt($now->startOfDay()); $day = $day->addDay()) {
            if (! WorkingDays::isWorkingDay($day)) {
                continue;
            }

            foreach ($users as $user) {
                $calls = fake()->numberBetween(4, 8);

                for ($n = 0; $n < $calls; $n++) {
                    $moment = $day->setTime(8 + intdiv($n * 8, $calls), fake()->numberBetween(0, 59));

                    $this->at($moment, function () use ($user, $outcomes, &$objections) {
                        $lead = $this->nextLead($user);

                        if (! $lead) {
                            return;
                        }

                        $lead->assigned_to ??= $user->id;
                        $lead->saveQuietly();

                        $outcome = $this->weighted($outcomes);

                        if ($outcome === 'objection' && ($objections >= 2 || $lead->contact_id)) {
                            $outcome = 'no_interest';
                        }

                        if ($outcome === 'cross_selling') {
                            $this->service->toggleCrossSelling($lead, today()->addDays(fake()->numberBetween(5, 40))->toDateString(), 'Interesse an Anzeigenbetreuung', $user);
                            $outcome = 'no_interest';
                        }

                        try {
                            $this->service->apply($lead, $outcome, $this->dataFor($outcome), $user, asCall: $outcome !== 'handed_over');
                            $objections += $outcome === 'objection' ? 1 : 0;
                        } catch (ValidationException) {
                            // z. B. Sperrliste: Vorgang als „Kein Bedarf“ schließen.
                            $this->service->apply($lead, 'no_need', ['note' => 'Nicht angerufen (Sperrliste)'], $user);
                        }
                    });
                }
            }
        }
    }

    private function nextLead(User $user): ?Lead
    {
        return Lead::query()
            ->with(['organization', 'contact'])
            ->whereNull('closed_at')
            ->whereNotNull('organization_id')
            ->where('status', '!=', 'handed_over')
            ->where(fn ($q) => $q->whereNull('next_action_at')->orWhereDate('next_action_at', '<=', today()))
            ->where(fn ($q) => $q->whereNull('assigned_to')->orWhere('assigned_to', $user->id))
            ->inRandomOrder()
            ->first();
    }

    /** @return array<string, mixed> */
    private function dataFor(string $outcome): array
    {
        $notes = [
            'not_reached' => ['Mailbox', 'Besetzt', 'Zentrale, Ansprechpartner nicht im Haus', null],
            'interested' => ['Interesse an KI-Grundlagen für das Büroteam', 'Bitte in zwei Wochen erneut anrufen', 'Rückruf der Geschäftsführung erbeten'],
            'documents_sent' => ['Unterlagen und Datenschutzhinweis per E-Mail gesendet'],
            'appointment' => ['Beratungstermin vereinbart'],
            'later' => ['Erst nach dem Jahresabschluss wieder Zeit', 'Interesse am nächsten Durchlauf'],
            'no_interest' => ['Kein Weiterbildungsbedarf', 'Hat bereits einen Anbieter'],
            'no_need' => ['Ein-Mann-Betrieb, kein Büro'],
            'wrong_data' => ['Nummer nicht vergeben'],
            'objection' => ['Möchte nicht mehr kontaktiert werden'],
            'handed_over' => ['Weiter im Förderweg (Stufe 3)'],
        ];

        return array_filter([
            'note' => fake()->randomElement($notes[$outcome] ?? [null]),
            'next_action_at' => in_array($outcome, ['interested', 'later'], true) ? today()->addDays(fake()->numberBetween(3, $outcome === 'later' ? 90 : 14))->toDateString() : null,
            'appointment' => $outcome === 'appointment' ? [
                'date' => WorkingDays::add(today(), fake()->numberBetween(1, 8))->toDateString(),
                'time' => fake()->randomElement(['09:00', '10:30', '13:00', '14:30', '16:00']),
                'type' => fake()->randomElement(['phone', 'teams', 'onsite']),
            ] : null,
            'close_reason' => $outcome === 'wrong_data' ? fake()->randomElement(array_keys(config('adk.wrong_data_reasons'))) : null,
            'confirmed' => $outcome === 'objection' ?: null,
            'contact' => $outcome === 'documents_sent' ? ['last_name' => fake()->lastName(), 'email' => fake()->unique()->userName().'@example.org'] : null,
            'target_group' => $outcome === 'handed_over' ? 'C' : null,
        ]);
    }

    /** @param array<string, int> $weights */
    private function weighted(array $weights): string
    {
        $pick = fake()->numberBetween(1, array_sum($weights));

        foreach ($weights as $key => $weight) {
            if (($pick -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    private function at(CarbonImmutable $moment, callable $callback): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);

        $callback();
    }
}
