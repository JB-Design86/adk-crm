<?php

use App\Filament\Resources\BlocklistEntries\BlocklistEntryResource;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\AuditLog;
use App\Models\BlocklistEntry;
use App\Models\Lead;
use App\Models\User;
use App\Services\ImportService;
use App\Services\LeadExportService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

$adminOnly = ['/benutzer', '/benutzer/create', '/import', '/importe', '/sperrliste', '/sperrliste/create', '/protokoll'];
$everyone = ['/', '/vorgaenge', '/vorgaenge/create', '/organisationen', '/kontakte'];

it('verwehrt Mitarbeitenden Benutzerverwaltung, Import, Sperrliste und Protokoll', function (string $url) {
    loginAs('staff');

    $this->get($url)->assertForbidden();
})->with($adminOnly);

it('erlaubt der Verwaltung alle Bereiche', function (string $url) {
    loginAs('admin');

    $this->get($url)->assertOk();
})->with([...$adminOnly, ...$everyone]);

it('erlaubt Mitarbeitenden die Arbeitsbereiche', function (string $url) {
    loginAs('staff');

    $this->get($url)->assertOk();
})->with($everyone);

it('zeigt die Vorgangsseite', function () {
    loginAs('staff');
    $lead = Lead::factory()->create();

    $this->get("/vorgaenge/{$lead->id}")->assertOk()->assertSee($lead->organization->name);
});

it('verbietet Mitarbeitenden den Export', function () {
    $user = loginAs('staff');

    Livewire::test(ListLeads::class)->assertActionHidden(TestAction::make('export')->table());

    expect(fn () => app(LeadExportService::class)->export(Lead::query(), 'csv', [], $user))
        ->toThrow(AuthorizationException::class);
});

it('exportiert für die Verwaltung die gefilterte Liste und protokolliert den Export', function () {
    $user = loginAs('admin');
    Lead::factory()->count(3)->create();
    Lead::factory()->create(['status' => 'no_interest', 'closed_at' => now()]);

    Livewire::test(ListLeads::class)
        ->assertActionVisible(TestAction::make('export')->table())
        ->callAction(TestAction::make('export')->table(), ['format' => 'csv'])
        ->assertFileDownloaded();

    $log = AuditLog::where('log_name', 'export')->latest('id')->first();
    expect($log)->causer_id->toBe($user->id)
        ->and($log->properties['count'])->toBe(3) // Standardfilter: nur offene
        ->and($log->properties['filters'])->toHaveKey('filters');
});

it('verbietet Mitarbeitenden den Import', function () {
    loginAs('staff');

    expect(auth()->user()->can('import'))->toBeFalse();
    $this->get('/import')->assertForbidden();
});

it('lässt nur die Verwaltung Konten anlegen', function () {
    loginAs('admin');

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Neue Person',
            'email' => 'neu@example.org',
            'role' => 'staff',
            'password' => 'lang-genug-123',
            'password_confirmation' => 'lang-genug-123',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'neu@example.org')->first())->role->toBe('staff');
});

it('verlangt Kennwörter mit mindestens 10 Zeichen', function () {
    loginAs('admin');

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Kurz',
            'email' => 'kurz@example.org',
            'role' => 'staff',
            'password' => 'kurz123',
            'password_confirmation' => 'kurz123',
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);
});

it('protokolliert Änderungen an Benutzern und löscht Sperrlisteneinträge nur mit Begründung', function () {
    $admin = loginAs('admin');
    $user = User::factory()->create();

    $user->block();
    expect(AuditLog::where('log_name', 'users')->where('subject_id', $user->id)->where('event', 'updated')->exists())->toBeTrue();

    $entry = BlocklistEntry::create(['phone_e164' => '+496131000000', 'reason' => 'Test']);
    BlocklistEntryResource::deleteWithJustification($entry, 'Eintrag irrtümlich angelegt');

    expect(BlocklistEntry::count())->toBe(0);
    $log = AuditLog::where('log_name', 'blocklist')->where('event', 'deleted')->first();
    expect($log->properties['justification'])->toBe('Eintrag irrtümlich angelegt')
        ->and($log->causer_id)->toBe($admin->id);
});

it('macht Protokolleinträge unveränderlich', function () {
    loginAs('admin');
    $entry = AuditLog::query()->latest('id')->firstOrFail();

    expect(fn () => $entry->update(['description' => 'geändert']))->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('hält das Rollensystem erweiterbar', function () {
    config(['adk.roles.claude' => ['label' => 'Claude', 'permissions' => ['leads.view', 'leads.edit']]]);
    $user = User::factory()->state(['role' => 'claude'])->create();

    expect($user->hasPermission('leads.edit'))->toBeTrue()
        ->and($user->hasPermission('health.view'))->toBeFalse()
        ->and($user->hasPermission('export'))->toBeFalse();
});
