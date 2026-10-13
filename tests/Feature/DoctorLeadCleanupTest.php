<?php

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ImportLog;
use App\Models\Lead;
use App\Models\Organization;
use App\Services\DoctorLeadCleanup;

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

it('erkennt Arzt- und Zahnarztpraxen, Pflege und der übrige Gesundheitsbereich bleiben', function (?string $name, ?string $industry, ?string $wz, ?string $type) {
    expect(DoctorLeadCleanup::classify($name, $industry, $wz)[0])->toBe($type);
})->with([
    // Ärzte und Zahnärzte: werden gelöscht
    ['Zahnarztpraxis Dr. Müller', null, null, 'doctor'],
    ['Orthopädie am Markt', null, null, 'doctor'],
    ['Aerztehaus Mainz', null, null, 'doctor'],
    ['Gemeinschaftspraxis Allgemeinmedizin', null, null, 'doctor'],
    ['MVZ Rheinhessen GmbH', null, null, 'doctor'],
    ['ZMVZ Plus GmbH', null, null, 'doctor'],
    ['Fachpraxis für Kieferorthopädie Dr. Tegtmeyer', null, null, 'doctor'],
    ['Zahnarzt Jens Sommer', null, null, 'doctor'],
    ['Dr. med. Anna Schmitt', null, null, 'doctor'],
    ['Kieferorthopädie Lächeln', null, null, 'doctor'],
    ['Muster GmbH', null, '86.23', 'doctor'],
    // Sieht nach Arzt aus, bleibt: Pflege, Therapie, Klinik, Tiermedizin
    ['Pflege & Arztpraxis Sonnenschein', null, null, 'kept'],
    ['Tierarztpraxis Huber', null, null, 'kept'],
    ['Praxis für Physiotherapie Becker', null, null, 'kept'],
    ['Praxis für Ergotherapie', null, null, 'kept'],
    ['Orthopädie & Physiotherapie Zentrum', null, null, 'kept'],
    ['Klinik für Zahnmedizin', null, null, 'kept'],
    ['Orthopädietechnik Schmidt', null, null, 'kept'],
    ['Kosmetikpraxis Schön', null, null, 'kept'],
    ['Praxis für Ernährungsberatung', null, null, 'kept'],
    ['Kanzlei und Praxis Weber', null, null, 'kept'],
    // Kein Arzt: nicht betroffen
    ['Ambulanter Pflegedienst Sonnenschein', null, null, null],
    ['Pflege & Therapie GmbH', null, null, null],
    ['Apotheke am Dom', null, null, null],
    ['Muster GmbH', 'Gesundheitswesen', '86.90', null],
    ['Muster GmbH', null, '87.10', null],
    ['Fahrradklinik Mainz', null, null, null],
    ['Hospitality Consulting GmbH', null, null, null],
    ['Gebäudepflege Müller', null, null, null],
    ['Steuerberatung Dr. Klein', 'Steuerberatung', '69.20', null],
]);

it('listet im Probelauf nur neue, unbearbeitete Arztpraxen aus Leadlisten und löscht nichts', function () {
    $praxis = listLead('Zahnarztpraxis Dr. Müller');
    $worked = listLead('Hausarztpraxis Becker');
    Activity::create(['lead_id' => $worked->id, 'type' => 'note', 'body' => 'Notiz']);
    $called = listLead('Praxis Dr. Weber', ['call_attempts' => 1]);
    listLead('Kinderarztpraxis Klein', ['import_log_id' => null]);
    listLead('Augenarztpraxis Lang', ['status' => 'interested']);
    listLead('Tierarztpraxis Huber');
    listLead('Ambulanter Pflegedienst Sonnenschein');
    listLead('Steuerbüro Schulz');

    $this->artisan('adk:aerzte-aussortieren')
        ->expectsOutputToContain('Probelauf')
        ->expectsOutputToContain('Würde gelöscht (Arzt- und Zahnarztpraxen): 1')
        ->expectsOutputToContain('Bleibt trotz Arzt-Treffer (Pflege, Therapie, Klinik, Tiermedizin …): 1')
        ->expectsOutputToContain('Arztpraxen schon bearbeitet, bleiben (Anruf, Aktivität, Termin …): 2')
        ->assertSuccessful();

    $preview = app(DoctorLeadCleanup::class)->preview();

    expect(Lead::count())->toBe(8)
        ->and($preview['delete']->pluck('id')->all())->toBe([$praxis->id])
        ->and($preview['worked']->pluck('id')->all())->toBe([$worked->id, $called->id]);
});

it('löscht mit --loeschen endgültig, samt Betrieb und Ansprechperson, Pflegedienste bleiben, Protokoll nur mit Anzahlen', function () {
    $praxis = listLead('Zahnarztpraxis Dr. Müller');
    $kept = listLead('Praxis Dr. Ausnahme');
    $pflege = listLead('Pflegedienst & Hausarztpraxis Sonnenschein');
    $pflegedienst = listLead('Ambulanter Pflegedienst Mainz');
    $other = listLead('Steuerbüro Schulz');

    // Betrieb mit einem weiteren Vorgang bleibt, nur der neue Vorgang geht.
    $mvz = listLead('MVZ am Rhein');
    $older = Lead::factory()->create(['organization_id' => $mvz->organization_id, 'status' => 'interested']);

    $this->artisan('adk:aerzte-aussortieren', ['--loeschen' => true, '--ohne' => (string) $kept->id])
        ->expectsOutputToContain('Aussortiert: 2 Vorgänge, 1 Betriebe, 2 Kontakte.')
        ->assertSuccessful();

    expect(Lead::find($praxis->id))->toBeNull()
        ->and(Organization::find($praxis->organization_id))->toBeNull()
        ->and(Contact::find($praxis->contact_id))->toBeNull()
        ->and(Lead::find($mvz->id))->toBeNull()
        ->and(Organization::find($mvz->organization_id))->not->toBeNull()
        ->and(Lead::find($older->id))->not->toBeNull()
        ->and(Lead::find($kept->id))->not->toBeNull()
        ->and(Lead::find($pflege->id))->not->toBeNull()
        ->and(Lead::find($pflegedienst->id))->not->toBeNull()
        ->and(Lead::find($other->id))->not->toBeNull();

    $log = AuditLog::where('log_name', 'cleanup')->sole();
    expect($log->event)->toBe('sorted_out')
        ->and($log->properties['counts']['leads'])->toBe(2)
        ->and(json_encode($log->properties, JSON_UNESCAPED_UNICODE))->not->toContain('Müller')->not->toContain('MVZ am Rhein');
});
