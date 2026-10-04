<?php

use App\Filament\Pages\CallList;
use App\Filament\Resources\DuplicateCandidates\Pages\ListDuplicateCandidates;
use App\Filament\Resources\Organizations\Pages\CreateOrganization;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\DuplicateCandidate;
use App\Models\ImportLog;
use App\Models\Lead;
use App\Models\Organization;
use App\Services\Duplicates\DuplicateFinder;
use App\Services\Duplicates\DuplicateResolver;
use App\Services\ImportService;
use App\Support\Similarity;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(10, 0));
    $this->user = loginAs('admin');
    $this->finder = app(DuplicateFinder::class);
});

function existingCompany(array $attributes = []): Organization
{
    $organization = Organization::factory()->create([
        'name' => 'Müller Bau GmbH',
        'street' => 'Rheinstraße 12',
        'postal_code' => '55116',
        'city' => 'Mainz',
        'phone_display' => '06131 123456',
        'email' => 'info@mueller-bau.example',
        'website' => 'https://www.mueller-bau.example',
        ...$attributes,
    ]);
    Lead::factory()->create(['organization_id' => $organization->id, 'contact_id' => null, 'status' => 'interested']);

    return $organization;
}

function importRows(array $rows): ImportLog
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    $handle = fopen($path, 'w');
    fputcsv($handle, ['Firmenname', 'Straße', 'PLZ', 'Ort', 'Telefon', 'E-Mail', 'Website', 'Nachname Ansprechpartner', 'E-Mail Ansprechpartner'], ';', '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ';', '"', '');
    }
    fclose($handle);

    $service = app(ImportService::class);
    $header = $service->preview($path, 'csv')['header'];

    return $service->import($path, 'csv', 'liste.csv', 'Testliste', '2026-10-01', $service->guessMapping($header), test()->user);
}

it('erkennt sichere Dubletten über Telefon, E-Mail, Website und Name mit PLZ', function () {
    existingCompany();

    expect($this->finder->organizationMatches(['name' => 'Ganz anders', 'phone' => '+49 (0)6131 / 12 34 56'])->first())
        ->certain->toBeTrue()->reasons->toContain('gleiche Telefonnummer')
        ->and($this->finder->organizationMatches(['name' => 'X', 'email' => 'INFO@mueller-bau.example'])->first()->reasons)->toContain('gleiche E-Mail-Adresse')
        ->and($this->finder->organizationMatches(['name' => 'X', 'website' => 'mueller-bau.example/kontakt'])->first()->reasons)->toContain('gleiche Website')
        ->and($this->finder->organizationMatches(['name' => 'Müller Bau e.K.', 'postal_code' => '55116'])->first()->reasons)->toContain('gleicher Firmenname und gleiche PLZ');
});

it('meldet ähnliche Namen am selben Ort und gleiche Anschrift als Verdacht', function () {
    existingCompany();

    $typo = $this->finder->organizationMatches(['name' => 'Mueller Bau', 'postal_code' => '55118', 'city' => 'Mainz'])->first();
    $longer = $this->finder->organizationMatches(['name' => 'Müller Bau und Sanierung GmbH', 'postal_code' => '55116'])->first();
    $address = $this->finder->organizationMatches(['name' => 'Bauunternehmen Müller', 'street' => 'Rheinstr. 12', 'postal_code' => '55116'])->first();
    $mailDomain = $this->finder->organizationMatches(['name' => 'MB Service', 'email' => 'kontakt@mueller-bau.example'])->first();
    Organization::factory()->create(['name' => 'Schreinerei Weber', 'postal_code' => '55122', 'city' => 'Mainz']);
    $typoLong = $this->finder->organizationMatches(['name' => 'Schreinerei Webber', 'city' => 'Mainz'])->first();

    expect($typo)->certain->toBeFalse()->score->toBeGreaterThanOrEqual(75)
        ->and($longer->score)->toBeGreaterThanOrEqual(75)
        ->and($address->reasons)->toContain('gleiche Anschrift')
        ->and($mailDomain->reasons)->toContain('E-Mail-Domain passt zur Website')
        ->and($typoLong->reasons)->toContain('sehr ähnlicher Firmenname am selben Ort');
});

it('meldet keine Dublette bei gleichem Namen in einer anderen Stadt oder bei Freemail-Adressen', function () {
    existingCompany(['name' => 'Bäckerei Schmidt', 'email' => 'baeckerei.schmidt@gmail.com', 'website' => null, 'phone_display' => null]);

    Organization::factory()->create(['name' => 'Brunner & Partner GmbH', 'postal_code' => '55268', 'city' => 'Nieder-Olm', 'phone_display' => null, 'email' => null, 'website' => null, 'street' => 'Lindenweg 1']);

    expect($this->finder->organizationMatches(['name' => 'Bäckerei Schmidt', 'postal_code' => '60311', 'city' => 'Frankfurt']))->toBeEmpty()
        ->and($this->finder->organizationMatches(['name' => 'Brenner & Partner KG', 'postal_code' => '55268', 'city' => 'Nieder-Olm']))->toBeEmpty()
        ->and($this->finder->organizationMatches(['name' => 'Malerei Schulz', 'email' => 'maler.schulz@gmail.com']))->toBeEmpty();
});

it('berechnet Namensähnlichkeit robust gegen Tippfehler und Wortreihenfolge', function () {
    expect(Similarity::companyNames('Müller Bau GmbH', 'Mueller Bau'))->toBe(1.0)
        ->and(Similarity::companyNames('Müller Bau', 'Bau Müller GmbH & Co. KG'))->toBeGreaterThanOrEqual(0.9)
        ->and(Similarity::companyNames('Schreinerei Weber', 'Schreinerei Webber'))->toBeGreaterThanOrEqual(0.9)
        ->and(Similarity::companyNames('Autohaus Kern', 'Zahnarztpraxis Berg'))->toBeLessThan(0.7);
});

it('gleicht die Leadliste gegen alle Phasen ab: Förderfall, Teilnehmer, Kontakte', function () {
    $company = existingCompany();
    Lead::where('organization_id', $company->id)->update(['status' => 'handed_over']);
    Contact::factory()->create(['organization_id' => null, 'is_private' => true, 'last_name' => 'Beispiel', 'email' => 'person@beispiel.example']);

    $log = importRows([
        ['Ganz neu GmbH', '', '55122', 'Mainz', '06131 123456', '', '', '', ''],                                // Telefon wie Förderfall
        ['Andere Firma', '', '55122', 'Mainz', '', '', '', 'Beispiel', 'person@beispiel.example'],             // E-Mail wie Kontakt
        ['Mueller Bau', '', '55118', 'Mainz', '', '', '', '', ''],                                               // Verdacht
        ['Wirklich neu AG', '', '55124', 'Mainz', '06131 999999', '', '', '', ''],
    ]);

    expect($log)->rows_imported->toBe(2)->duplicates->toBe(2)->suspected_duplicates->toBe(1)
        ->and($log->skipped_rows[0]['reason'])->toContain('gleiche Telefonnummer')->toContain('Förderfall')
        ->and($log->skipped_rows[1]['reason'])->toContain('wie Kontakt');

    $suspect = Organization::where('name', 'Mueller Bau')->sole();
    expect(DuplicateCandidate::isFlagged($suspect))->toBeTrue();
});

it('ruft Verdachtsfälle erst nach der Entscheidung in der Anrufliste auf', function () {
    loginAs('staff');
    $company = existingCompany();
    $suspect = Organization::factory()->create(['name' => 'Mueller Bau', 'postal_code' => '55116', 'city' => 'Mainz', 'phone_display' => '06131 777777']);
    $lead = Lead::factory()->create(['organization_id' => $suspect->id, 'contact_id' => null, 'status' => 'new', 'next_action_at' => null]);
    $this->finder->record($suspect);

    expect((new CallList)->queue()->pluck('leads.id'))->not->toContain($lead->id);

    app(DuplicateResolver::class)->reject(DuplicateCandidate::sole());

    expect((new CallList)->queue()->pluck('leads.id'))->toContain($lead->id);
    $this->finder->record($suspect);
    expect(DuplicateCandidate::open()->count())->toBe(0);
});

it('führt Dubletten zusammen: Felder ergänzen, Kontakte umziehen, leeren Importvorgang entfernen', function () {
    $company = existingCompany(['email' => null, 'employee_count' => null]);
    $suspect = Organization::factory()->create(['name' => 'Mueller Bau', 'postal_code' => '55116', 'city' => 'Mainz', 'phone_display' => '06131 555555', 'email' => 'buero@mueller-bau.example', 'employee_count' => 25]);
    $contact = Contact::factory()->create(['organization_id' => $suspect->id, 'last_name' => 'Neumann', 'is_private' => false]);
    $freshLead = Lead::factory()->create(['organization_id' => $suspect->id, 'contact_id' => $contact->id, 'status' => 'new']);
    $this->finder->record($suspect);

    app(DuplicateResolver::class)->merge(DuplicateCandidate::sole());

    $company->refresh();
    expect(Organization::find($suspect->id))->toBeNull()
        ->and($company->email)->toBe('buero@mueller-bau.example')
        ->and($company->employee_count)->toBe(25)
        ->and($company->phone_display)->toBe('06131 123456')
        ->and($contact->fresh()->organization_id)->toBe($company->id)
        ->and(Lead::find($freshLead->id))->toBeNull()
        ->and(DuplicateCandidate::sole()->state)->toBe('merged');
});

it('behält beim Zusammenführen Vorgänge mit Verlauf', function () {
    $company = existingCompany();
    $suspect = Organization::factory()->create(['name' => 'Mueller Bau', 'postal_code' => '55116', 'city' => 'Mainz']);
    $lead = Lead::factory()->create(['organization_id' => $suspect->id, 'contact_id' => null, 'status' => 'interested']);
    Activity::create(['lead_id' => $lead->id, 'type' => 'call', 'body' => 'Gespräch']);
    $this->finder->record($suspect);

    app(DuplicateResolver::class)->merge(DuplicateCandidate::sole());

    expect($lead->fresh()->organization_id)->toBe($company->id);
});

it('zeigt beim Anlegen einer Organisation ähnliche Einträge und nimmt sie in die Prüfung', function () {
    existingCompany();

    Livewire::test(CreateOrganization::class)
        ->fillForm(['name' => 'Müller Bau', 'postal_code' => '55116', 'city' => 'Mainz', 'source' => 'Telefonat', 'retrieved_at' => '2026-10-05'])
        ->assertSee('Ähnliche Einträge im CRM')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(DuplicateCandidate::open()->count())->toBe(1);
});

it('listet Verdachtsfälle und prüft auf Wunsch den ganzen Bestand', function () {
    existingCompany();
    Organization::factory()->create(['name' => 'Mueller Bau', 'postal_code' => '55116', 'city' => 'Mainz']);

    Livewire::test(ListDuplicateCandidates::class)
        ->callAction('scan')
        ->assertNotified('1 neuer Verdachtsfall');

    Livewire::test(ListDuplicateCandidates::class)
        ->assertSee('Mueller Bau')
        ->assertSee('Müller Bau GmbH')
        ->callTableAction('reject', DuplicateCandidate::sole());

    expect(DuplicateCandidate::sole()->state)->toBe('rejected');

    $this->artisan('adk:dubletten-pruefen')->expectsOutputToContain('0 neue Verdachtsfälle');
});

it('erkennt Personen über E-Mail und Telefon, aber nicht die gemeinsame Zentrale im Betrieb', function () {
    $company = Organization::factory()->create();
    Contact::factory()->create(['organization_id' => $company->id, 'first_name' => 'Anna', 'last_name' => 'Kurz', 'phone_display' => '06131 100', 'email' => 'a.kurz@firma.example', 'is_private' => false]);

    expect($this->finder->contactMatches(['last_name' => 'Lang', 'email' => 'A.Kurz@firma.example'])->first()->certain)->toBeTrue()
        ->and($this->finder->contactMatches(['last_name' => 'Lang', 'phone' => '06131 100', 'organization_id' => $company->id]))->toBeEmpty()
        ->and($this->finder->contactMatches(['first_name' => 'Anna', 'last_name' => 'Kurz', 'organization_id' => $company->id])->first()->reasons)->toContain('gleicher Name im selben Betrieb');
});
