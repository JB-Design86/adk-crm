<?php

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ImportLog;
use App\Models\Lead;
use App\Models\Organization;
use App\Services\HealthSectorCleanup;

beforeEach(function () {
    $this->import = ImportLog::create(['file_name' => 'premium-kunden.xlsx', 'source' => 'Recherche', 'retrieved_at' => '2026-10-10', 'rows_imported' => 10]);
});

/** Neuer, unbearbeiteter Vorgang aus der Leadliste mit Ansprechperson. */
function listLead(string $name, array $lead = [], array $organization = []): Lead
{
    $org = Organization::factory()->create(['name' => $name, 'industry' => null, 'wz_code' => null, ...$organization]);

    return Lead::factory()->create([
        'organization_id' => $org->id,
        'contact_id' => Contact::factory()->state(['organization_id' => $org->id]),
        'import_log_id' => test()->import->id,
        'status' => 'new',
        'call_attempts' => 0,
        ...$lead,
    ]);
}

it('erkennt Praxen, Ärzte, Therapeuten und Kliniken und trennt Grenzfälle und Fehlgriffe ab', function (?string $name, ?string $industry, ?string $wz, ?string $type) {
    expect(HealthSectorCleanup::classify($name, $industry, $wz)[0])->toBe($type);
})->with([
    ['Zahnarztpraxis Dr. Müller', null, null, 'health'],
    ['Praxis für Physiotherapie Becker', null, null, 'health'],
    ['Orthopädie am Markt', null, null, 'health'],
    ['Aerztehaus Mainz', null, null, 'health'],
    ['MVZ Rheinhessen GmbH', null, null, 'health'],
    ['Dr. med. Anna Schmitt', null, null, 'health'],
    ['Muster GmbH', 'Gesundheitswesen', null, 'health'],
    ['Muster GmbH', null, '86.23', 'health'],
    ['Tierarztpraxis Huber', null, null, 'borderline'],
    ['Apotheke am Dom', null, null, 'borderline'],
    ['Ambulanter Pflegedienst Sonnenschein', null, null, 'borderline'],
    ['Orthopädietechnik Schmidt', null, null, 'borderline'],
    ['Muster GmbH', null, '87.10', 'borderline'],
    ['Fahrradklinik Mainz', null, null, null],
    ['Hospitality Consulting GmbH', null, null, null],
    ['REHAU AG', null, null, null],
    ['Gebäudepflege Müller', null, null, null],
    ['Steuerberatung Dr. Klein', 'Steuerberatung', '69.20', null],
]);

it('listet im Probelauf nur neue, unbearbeitete Vorgänge aus Leadlisten und löscht nichts', function () {
    $praxis = listLead('Zahnarztpraxis Dr. Müller');
    $worked = listLead('Physiotherapie Becker');
    Activity::create(['lead_id' => $worked->id, 'type' => 'note', 'body' => 'Notiz']);
    $called = listLead('Praxis Dr. Weber', ['call_attempts' => 1, 'status' => 'new']);
    listLead('Hausarztpraxis Klein', ['import_log_id' => null]);
    listLead('Kinderarztpraxis Lang', ['status' => 'interested']);
    listLead('Apotheke am Dom');
    listLead('Steuerbüro Schulz');

    $this->artisan('adk:gesundheit-aussortieren')
        ->expectsOutputToContain('Probelauf')
        ->expectsOutputToContain('Würde gelöscht: 1')
        ->expectsOutputToContain('Grenzfälle, bleiben (mit --grenzfaelle auch löschen): 1')
        ->expectsOutputToContain('Schon bearbeitet, bleiben (Anruf, Aktivität, Termin …): 2')
        ->assertSuccessful();

    expect(Lead::count())->toBe(7)
        ->and(app(HealthSectorCleanup::class)->preview()['delete']->pluck('id')->all())->toBe([$praxis->id])
        ->and(app(HealthSectorCleanup::class)->preview()['worked']->pluck('id')->all())->toBe([$worked->id, $called->id]);
});

it('löscht mit --loeschen endgültig, samt Betrieb und Ansprechperson, und protokolliert nur Anzahlen', function () {
    $praxis = listLead('Zahnarztpraxis Dr. Müller');
    $kept = listLead('Praxis für Logopädie');
    $apotheke = listLead('Apotheke am Dom');
    $other = listLead('Steuerbüro Schulz');

    // Betrieb mit einem weiteren Vorgang bleibt, nur der neue Vorgang geht.
    $clinic = listLead('Klinik am Rhein');
    $older = Lead::factory()->create(['organization_id' => $clinic->organization_id, 'status' => 'interested']);

    $this->artisan('adk:gesundheit-aussortieren', ['--loeschen' => true, '--ohne' => (string) $kept->id])
        ->expectsOutputToContain('Aussortiert: 2 Vorgänge, 1 Betriebe, 2 Kontakte.')
        ->assertSuccessful();

    expect(Lead::find($praxis->id))->toBeNull()
        ->and(Organization::find($praxis->organization_id))->toBeNull()
        ->and(Contact::find($praxis->contact_id))->toBeNull()
        ->and(Lead::find($clinic->id))->toBeNull()
        ->and(Organization::find($clinic->organization_id))->not->toBeNull()
        ->and(Lead::find($older->id))->not->toBeNull()
        ->and(Lead::find($kept->id))->not->toBeNull()
        ->and(Lead::find($apotheke->id))->not->toBeNull()
        ->and(Lead::find($other->id))->not->toBeNull();

    $log = AuditLog::where('log_name', 'cleanup')->sole();
    expect($log->event)->toBe('sorted_out')
        ->and($log->properties['counts']['leads'])->toBe(2)
        ->and(json_encode($log->properties, JSON_UNESCAPED_UNICODE))->not->toContain('Müller')->not->toContain('Klinik am Rhein');

    // Grenzfälle nur auf ausdrücklichen Wunsch.
    $this->artisan('adk:gesundheit-aussortieren', ['--loeschen' => true, '--grenzfaelle' => true, '--ohne' => (string) $kept->id])->assertSuccessful();
    expect(Lead::find($apotheke->id))->toBeNull()
        ->and(Lead::find($kept->id))->not->toBeNull()
        ->and(Lead::find($other->id))->not->toBeNull();
});
