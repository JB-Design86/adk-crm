<?php

use App\Filament\Resources\CheckLevels\Pages\CreateCheckLevel;
use App\Filament\Resources\CheckLevels\Pages\EditCheckLevel;
use App\Filament\Resources\Organizations\Pages\EditOrganization;
use App\Models\AuditLog;
use App\Models\CheckLevel;
use App\Models\Contact;
use App\Models\FundingStep;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\OrganizationCheck;
use App\Models\ParticipantChecklistItem;
use App\Services\FundingService;
use App\Services\ImportService;
use App\Support\Spreadsheet;
use Livewire\Livewire;

it('übernimmt die bisherigen fünf Prüfstufen', function () {
    expect(CheckLevel::query()->ordered()->pluck('name')->all())
        ->toBe(['Prüfstufe 1', 'Prüfstufe 2', 'Prüfstufe 3', 'Prüfstufe 4', 'Prüfstufe 5']);
});

it('lässt nur die Verwaltung Prüfstufen pflegen', function () {
    loginAs('staff');
    $this->get('/pruefstufen')->assertForbidden();

    loginAs('admin');
    $this->get('/pruefstufen')->assertOk()->assertSee('Prüfstufe 1');
});

it('legt Prüfstufen an, benennt sie um und protokolliert das', function () {
    loginAs('admin');

    Livewire::test(CreateCheckLevel::class)
        ->fillForm(['name' => 'Webseite aktiv', 'description' => 'Hat der Betrieb eine erreichbare Website?', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $level = CheckLevel::where('name', 'Webseite aktiv')->sole();
    expect($level->sort_order)->toBe(6);

    Livewire::test(EditCheckLevel::class, ['record' => $level->id])
        ->fillForm(['name' => 'Website erreichbar'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($level->fresh()->name)->toBe('Website erreichbar')
        ->and(AuditLog::where('log_name', 'check_levels')->where('subject_id', $level->id)->count())->toBe(2);
});

it('speichert Ergebnisse je Prüfstufe im Formular der Organisation', function () {
    loginAs('staff');
    $organization = Organization::factory()->create();
    [$first, $second, $third] = CheckLevel::query()->ordered()->take(3)->get();

    Livewire::test(EditOrganization::class, ['record' => $organization->id])
        ->assertFormFieldExists("checks.{$first->id}")
        ->fillForm(["checks.{$first->id}" => 'passed', "checks.{$second->id}" => 'failed', "checks.{$third->id}" => 'open'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($organization->fresh()->checkStates())
        ->toMatchArray([$first->id => 'passed', $second->id => 'failed', $third->id => 'open']);

    // Zurück auf „offen“ entfernt das Ergebnis; die anderen Stufen bleiben.
    Livewire::test(EditOrganization::class, ['record' => $organization->id])
        ->assertFormSet(["checks.{$first->id}" => 'passed', "checks.{$second->id}" => 'failed'])
        ->set("data.checks.{$first->id}", 'open')
        ->call('save');

    expect(OrganizationCheck::where('organization_id', $organization->id)->count())->toBe(1);
});

it('zeigt inaktive Prüfstufen weder im Formular noch im Import, behält aber ihre Ergebnisse', function () {
    loginAs('admin');
    $organization = Organization::factory()->create();
    $level = CheckLevel::query()->ordered()->first();
    $organization->syncChecks([$level->id => 'passed']);

    $level->update(['is_active' => false]);

    Livewire::test(EditOrganization::class, ['record' => $organization->id])
        ->assertFormFieldDoesNotExist("checks.{$level->id}");

    expect(ImportService::fields())->not->toHaveKey(ImportService::CHECK_PREFIX.$level->id)
        ->and(OrganizationCheck::where('check_level_id', $level->id)->count())->toBe(1);
});

it('löscht beim Löschen einer Prüfstufe auch ihre Ergebnisse', function () {
    loginAs('admin');
    $organization = Organization::factory()->create();
    $level = CheckLevel::query()->ordered()->first();
    $organization->syncChecks([$level->id => 'failed']);

    Livewire::test(EditCheckLevel::class, ['record' => $level->id])->callAction('delete');

    expect(CheckLevel::find($level->id))->toBeNull()
        ->and(OrganizationCheck::where('check_level_id', $level->id)->count())->toBe(0);
});

it('importiert Spalten mit dem aktuellen Namen der Prüfstufe', function () {
    $user = loginAs('admin');
    $level = CheckLevel::query()->ordered()->first();
    $level->update(['name' => 'Büro vorhanden']);

    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    file_put_contents($path, "Firmenname;PLZ;Büro vorhanden\nMuster GmbH;55116;ja\nZweite GmbH;55122;nein\n");

    $service = app(ImportService::class);
    $mapping = $service->guessMapping($service->preview($path, 'csv')['header']);
    expect($mapping[ImportService::CHECK_PREFIX.$level->id])->toBe(2);

    $service->import($path, 'csv', 'x.csv', 'Test', '2026-09-20', $mapping, $user);
    @unlink($path);

    expect(Organization::where('name', 'Muster GmbH')->sole()->checkStates()[$level->id])->toBe('passed')
        ->and(Organization::where('name', 'Zweite GmbH')->sole()->checkStates()[$level->id])->toBe('failed');
});

it('erzeugt die Mustervorlage mit den aktuellen Prüfstufen', function () {
    CheckLevel::query()->ordered()->first()->update(['name' => 'Büro vorhanden']);
    CheckLevel::create(['name' => 'Neue Stufe']);

    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    ImportService::writeTemplate($path);
    $rows = iterator_to_array(Spreadsheet::rows($path, 'xlsx'), false);
    @unlink($path);

    expect($rows[0])->toContain('Büro vorhanden', 'Neue Stufe', 'Firmenname', 'Bemerkung Prüfung')
        ->not->toContain('Prüfstufe 1')
        ->and(count($rows))->toBe(3)
        ->and(count($rows[1]))->toBe(count($rows[0]));
});

it('überträgt Prüfstufen, Förderweg-Schritte und Checkliste in ein neues System', function () {
    CheckLevel::query()->first()->update(['name' => 'Ausbildungsbetrieb geprüft']);
    FundingStep::query()->first()->update(['instructions' => 'Beratungstermin per Teams anbieten.']);
    $file = tempnam(sys_get_temp_dir(), 'einstellungen').'.json';

    $this->artisan('adk:einstellungen', ['aktion' => 'export', 'datei' => $file])->assertSuccessful();
    $export = json_decode(file_get_contents($file), true);
    expect($export['check_levels'][0]['name'])->toBe('Ausbildungsbetrieb geprüft')
        ->and(json_encode($export))->not->toContain('@');

    // Neues System: Standardwerte
    CheckLevel::query()->first()->update(['name' => 'Prüfstufe 1']);
    FundingStep::query()->first()->update(['instructions' => null]);

    $this->artisan('adk:einstellungen', ['aktion' => 'import', 'datei' => $file])->assertSuccessful();

    expect(CheckLevel::orderBy('sort_order')->first()->name)->toBe('Ausbildungsbetrieb geprüft')
        ->and(FundingStep::where('instructions', 'Beratungstermin per Teams anbieten.')->exists())->toBeTrue()
        ->and(ParticipantChecklistItem::where('key', 'funder_confirmed')->exists())->toBeTrue();
});

it('spielt Einstellungen nicht in ein System mit Förderfällen ein', function () {
    $file = tempnam(sys_get_temp_dir(), 'einstellungen').'.json';
    $this->artisan('adk:einstellungen', ['aktion' => 'export', 'datei' => $file])->assertSuccessful();

    $lead = Lead::factory()->inboundPrivate()->create(['target_group' => 'self_payer', 'contact_id' => Contact::factory()->private()]);
    app(FundingService::class)->start($lead);

    $this->artisan('adk:einstellungen', ['aktion' => 'import', 'datei' => $file])->assertFailed();
});
