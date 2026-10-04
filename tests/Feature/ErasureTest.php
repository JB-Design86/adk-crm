<?php

use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Organizations\Pages\EditOrganization;
use App\Models\Activity;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\BlocklistEntry;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DuplicateCandidate;
use App\Models\ImportLog;
use App\Models\Lead;
use App\Models\Organization;
use App\Services\Documents\DocumentService;
use App\Services\ErasureService;
use App\Services\FundingService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(10, 0));
    $this->user = loginAs('admin');
    $this->erasure = app(ErasureService::class);
});

function privatePersonWithHistory(): Lead
{
    $lead = Lead::factory()->inboundPrivate()->create([
        'contact_id' => Contact::factory()->private()->state(['email' => 'erika@beispiel.example', 'phone_display' => '0151 2345678']),
    ]);
    Activity::create(['lead_id' => $lead->id, 'type' => 'call', 'body' => 'Rückruf erbeten']);
    Appointment::create(['lead_id' => $lead->id, 'starts_at' => now()->addDay(), 'type' => 'phone']);
    app(DocumentService::class)->store($lead, fakePdf(), ['category' => 'other']);

    return $lead;
}

it('löscht eine Privatperson auf Anfrage mit allem und setzt sie auf die Sperrliste', function () {
    $lead = privatePersonWithHistory();
    $contact = $lead->contact;
    $path = Document::sole()->path;

    $counts = $this->erasure->erase($contact, 'E-Mail vom 04.10.2026', blocklist: true);

    expect($counts)->leads->toBe(1)->contacts->toBe(1)->documents->toBe(1)
        ->and(Lead::find($lead->id))->toBeNull()
        ->and(Contact::find($contact->id))->toBeNull()
        ->and(Activity::count())->toBe(0)
        ->and(Appointment::count())->toBe(0)
        ->and(Storage::disk('documents')->exists($path))->toBeFalse()
        ->and(AuditLog::where('subject_type', Contact::class)->where('subject_id', $contact->id)->exists())->toBeFalse()
        ->and(AuditLog::where('subject_type', Lead::class)->where('subject_id', $lead->id)->exists())->toBeFalse();

    expect(BlocklistEntry::findMatch(email: 'erika@beispiel.example'))->not->toBeNull()
        ->and(BlocklistEntry::findMatch('+491512345678'))->not->toBeNull();

    $log = AuditLog::where('log_name', 'erasure')->sole();
    expect($log->event)->toBe('erased')
        ->and(json_encode($log->properties))->not->toContain('erika')->toContain('E-Mail vom 04.10.2026');
});

it('entfernt bei einer Ansprechperson nur die Person, der Vorgang des Betriebs bleibt', function () {
    $organization = Organization::factory()->create();
    $contact = Contact::factory()->create(['organization_id' => $organization->id, 'is_private' => false]);
    $lead = Lead::factory()->create(['organization_id' => $organization->id, 'contact_id' => $contact->id]);

    $this->erasure->erase($contact, 'Anruf vom 05.10.2026', blocklist: false);

    expect(Contact::find($contact->id))->toBeNull()
        ->and($lead->fresh())->not->toBeNull()
        ->and($lead->fresh()->contact_id)->toBeNull()
        ->and(BlocklistEntry::count())->toBe(0);
});

it('löscht einen Betrieb mit Kontakten, Vorgängen, Dublettenverdacht und Namen im Importprotokoll', function () {
    $organization = Organization::factory()->create(['name' => 'Muster Werkstatt GmbH', 'postal_code' => '55116']);
    Contact::factory()->count(2)->create(['organization_id' => $organization->id, 'is_private' => false]);
    $lead = Lead::factory()->create(['organization_id' => $organization->id, 'contact_id' => null]);
    $other = Organization::factory()->create();
    DuplicateCandidate::create(['type' => 'organization', 'subject_id' => $other->id, 'match_id' => $organization->id, 'score' => 80, 'reasons' => ['Test']]);
    $log = ImportLog::create(['file_name' => 'liste.xlsx', 'source' => 'Test', 'retrieved_at' => today(), 'user_id' => $this->user->id,
        'skipped_rows' => [['row' => 2, 'name' => 'Muster Werkstatt', 'reason' => 'Dublette', 'type' => 'duplicate']]]);

    $counts = $this->erasure->erase($organization, 'Schreiben vom 01.10.2026', blocklist: true);

    expect($counts)->organizations->toBe(1)->contacts->toBe(2)->leads->toBe(1)
        ->and(Organization::find($organization->id))->toBeNull()
        ->and(Lead::find($lead->id))->toBeNull()
        ->and(DuplicateCandidate::count())->toBe(0)
        ->and($log->fresh()->skipped_rows[0]['name'])->toBe('(gelöscht)')
        ->and(BlocklistEntry::findMatch(companyName: 'Muster Werkstatt GmbH', postalCode: '55116'))->not->toBeNull();
});

it('löscht nicht, solange eine Teilnehmerakte aufbewahrt werden muss', function () {
    $lead = Lead::factory()->inboundPrivate()->create(['target_group' => 'self_payer', 'contact_id' => Contact::factory()->private()]);
    $case = app(FundingService::class)->start($lead);
    foreach ($case->steps() as $step) {
        app(FundingService::class)->completeStep($case, $step, []);
    }
    uploadRequiredDocuments($case);
    app(FundingService::class)->enroll($case->fresh());

    expect($this->erasure->preview($lead->contact)['blockers'])->toHaveCount(1)
        ->and(fn () => $this->erasure->erase($lead->contact, 'E-Mail', true))->toThrow(ValidationException::class, 'Aufbewahrungspflicht');

    expect(Lead::find($lead->id))->not->toBeNull();
});

it('bietet das Löschersuchen nur der Verwaltung an und führt es aus der Oberfläche aus', function () {
    $organization = Organization::factory()->create();

    Livewire::test(EditOrganization::class, ['record' => $organization->id])
        ->assertActionVisible('erase')
        ->callAction('erase', ['reason' => 'E-Mail vom 04.10.2026', 'blocklist' => true, 'confirmed' => true])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    expect(Organization::find($organization->id))->toBeNull();

    loginAs('staff');
    $contact = Contact::factory()->create();
    Livewire::test(EditContact::class, ['record' => $contact->id])->assertActionHidden('erase');
});

it('verlangt die Bestätigung im Dialog', function () {
    $contact = Contact::factory()->create();

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->callAction('erase', ['reason' => 'E-Mail', 'blocklist' => false, 'confirmed' => false])
        ->assertHasActionErrors(['confirmed']);

    expect(Contact::find($contact->id))->not->toBeNull();
});
