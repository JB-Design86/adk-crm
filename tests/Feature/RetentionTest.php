<?php

use App\Models\Activity;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\BlocklistEntry;
use App\Models\Contact;
use App\Models\ImportLog;
use App\Models\Lead;
use App\Models\Organization;
use App\Services\RetentionService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 24)->setTime(2, 30));
    $this->user = loginAs('admin');
});

function oldLead(array $state, string $lastContact, ?callable $factory = null): Lead
{
    $lead = ($factory ? $factory(Lead::factory()) : Lead::factory())->create($state);
    $lead->forceFill(['last_contact_at' => $lastContact])->saveQuietly();
    Organization::query()->update(['updated_at' => '2020-01-01']);
    Contact::query()->update(['updated_at' => '2020-01-01']);

    return $lead;
}

it('löscht Vorgänge von Betrieben 24 Monate nach dem letzten Kontakt', function () {
    $expired = oldLead([], '2024-09-20');
    $kept = oldLead([], '2024-09-30');

    app(RetentionService::class)->run();

    expect(Lead::find($expired->id))->toBeNull()
        ->and(Lead::find($kept->id))->not->toBeNull();
});

it('löscht Vorgänge von Privatpersonen 6 Monate nach dem letzten Kontakt', function () {
    $expired = oldLead([], '2026-03-20', fn ($f) => $f->inboundPrivate());
    $kept = oldLead([], '2026-03-30', fn ($f) => $f->inboundPrivate());

    app(RetentionService::class)->run();

    expect(Lead::find($expired->id))->toBeNull()
        ->and(Lead::find($kept->id))->not->toBeNull();
});

it('behält Vorgänge mit Vertragsschluss', function () {
    $contracted = oldLead(['contracted_at' => '2023-01-01'], '2023-01-01');

    app(RetentionService::class)->run();

    expect(Lead::find($contracted->id))->not->toBeNull();
});

it('löscht Aktivitäten, Termine und Protokolleinträge mit dem Vorgang', function () {
    $lead = Lead::factory()->create();
    Activity::create(['lead_id' => $lead->id, 'type' => 'note', 'body' => 'vertraulich']);
    Appointment::create(['lead_id' => $lead->id, 'starts_at' => '2024-01-10 10:00', 'type' => 'phone']);
    $lead->forceFill(['last_contact_at' => '2024-01-10'])->saveQuietly();
    Organization::query()->update(['updated_at' => '2020-01-01']);

    expect(AuditLog::where('subject_type', Lead::class)->where('subject_id', $lead->id)->exists())->toBeTrue();

    $counts = app(RetentionService::class)->run();

    expect(Activity::count())->toBe(0)
        ->and(Appointment::count())->toBe(0)
        ->and(AuditLog::where('subject_type', Lead::class)->where('subject_id', $lead->id)->exists())->toBeFalse()
        ->and(AuditLog::where('description', 'like', '%vertraulich%')->orWhere('attribute_changes', 'like', '%vertraulich%')->exists())->toBeFalse()
        ->and($counts)->toMatchArray(['leads_company' => 1, 'activities' => 1, 'appointments' => 1]);
});

it('löscht Organisationen und Kontakte erst, wenn kein Vorgang mehr darauf verweist', function () {
    $organization = Organization::factory()->create();
    $contact = Contact::factory()->create(['organization_id' => $organization->id]);
    $old = Lead::factory()->create(['organization_id' => $organization->id, 'contact_id' => $contact->id]);
    $old->forceFill(['last_contact_at' => '2024-01-01'])->saveQuietly();
    $recent = Lead::factory()->create(['organization_id' => $organization->id]);
    Organization::query()->update(['updated_at' => '2020-01-01']);
    Contact::query()->update(['updated_at' => '2020-01-01']);

    app(RetentionService::class)->run();

    expect(Lead::find($old->id))->toBeNull()
        ->and(Organization::find($organization->id))->not->toBeNull()
        ->and(Contact::find($contact->id))->not->toBeNull();

    $recent->forceFill(['last_contact_at' => '2024-01-01'])->saveQuietly();
    app(RetentionService::class)->run();

    expect(Organization::find($organization->id))->toBeNull()
        ->and(Contact::find($contact->id))->toBeNull();
});

it('lässt gerade angelegte Stammdaten ohne Vorgang stehen', function () {
    $organization = Organization::factory()->create();

    app(RetentionService::class)->run();

    expect(Organization::find($organization->id))->not->toBeNull();
});

it('entfernt den Nachweis der Einwilligung 5 Jahre nach Erteilung bzw. letzter Verwendung', function () {
    $expired = Contact::factory()->private()->create(['phone_consent_at' => '2021-09-01', 'phone_consent_proof' => 'Formular']);
    $usedRecently = Contact::factory()->private()->create(['phone_consent_at' => '2021-09-01', 'phone_consent_proof' => 'Formular', 'phone_consent_last_used_at' => '2022-01-10']);
    Lead::factory()->create(['organization_id' => null, 'contact_id' => $expired->id, 'channel' => 'phone']);
    Lead::factory()->create(['organization_id' => null, 'contact_id' => $usedRecently->id, 'channel' => 'phone']);

    app(RetentionService::class)->run();

    expect($expired->fresh())->phone_consent_at->toBeNull()->phone_consent_proof->toBeNull()
        ->and($usedRecently->fresh()->phone_consent_proof)->toBe('Formular')
        ->and(AuditLog::where('subject_type', Contact::class)->where('subject_id', $expired->id)->where('attribute_changes', 'like', '%Formular%')->exists())->toBeFalse();
});

it('löscht Importprotokolle 3 Jahre nach Ende des Kalenderjahres', function () {
    $log2022 = ImportLog::create(['file_name' => 'a.csv', 'source' => 'Q', 'retrieved_at' => '2022-05-01']);
    $log2022->forceFill(['created_at' => '2022-12-31 12:00'])->saveQuietly();
    $log2023 = ImportLog::create(['file_name' => 'b.csv', 'source' => 'Q', 'retrieved_at' => '2023-01-02']);
    $log2023->forceFill(['created_at' => '2023-01-02 12:00'])->saveQuietly();

    app(RetentionService::class)->run();

    expect(ImportLog::find($log2022->id))->toBeNull()
        ->and(ImportLog::find($log2023->id))->not->toBeNull();
});

it('lässt die Sperrliste unberührt', function () {
    $entry = BlocklistEntry::create(['phone_e164' => '+496131000000', 'reason' => 'Werbewiderspruch']);
    $entry->forceFill(['created_at' => '2010-01-01', 'blocked_on' => '2010-01-01'])->saveQuietly();

    app(RetentionService::class)->run();

    expect(BlocklistEntry::find($entry->id))->not->toBeNull();
});

it('listet im Probelauf nur auf und löscht nichts', function () {
    $lead = oldLead([], '2024-01-01');

    $exit = Artisan::call('adk:loeschlauf', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Probelauf')
        ->toContain("Vorgang {$lead->id}")
        ->and(Lead::find($lead->id))->not->toBeNull()
        ->and(AuditLog::where('log_name', 'retention')->exists())->toBeFalse();
});

it('protokolliert nur Anzahl und Zeitpunkt ohne Personendaten', function () {
    $lead = oldLead([], '2024-01-01');
    $name = $lead->organization->name;

    Artisan::call('adk:loeschlauf');

    $entry = AuditLog::where('log_name', 'retention')->sole();
    expect($entry->properties['counts']['leads_company'])->toBe(1)
        ->and($entry->subject_id)->toBeNull()
        ->and(json_encode($entry->toArray()))->not->toContain($name);
});

it('ist als tägliche Aufgabe eingeplant', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

    expect($events->contains(fn ($event) => str_contains($event->command, 'adk:loeschlauf') && $event->expression === '30 2 * * *'))->toBeTrue();
});
