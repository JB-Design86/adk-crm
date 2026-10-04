<?php

use App\Filament\Pages\CallList;
use App\Filament\Resources\FundingCases\Pages\ViewFundingCase;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Leads\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Participants\Pages\ListParticipants;
use App\Filament\Resources\Participants\Pages\ViewParticipant;
use App\Filament\Resources\Participants\ParticipantResource;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Document;
use App\Models\FundingCase;
use App\Models\Lead;
use App\Models\Participant;
use App\Models\ParticipantChecklistItem;
use App\Services\Documents\DocumentService;
use App\Services\FundingService;
use App\Services\ParticipantArchive;
use App\Services\ParticipantService;
use App\Services\RetentionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(10, 0));
    $this->user = loginAs('admin');
    $this->funding = app(FundingService::class);
    $this->documents = app(DocumentService::class);
});

function caseReadyForEnrollment(string $targetGroup = 'A'): FundingCase
{
    $lead = Lead::factory()->inboundPrivate()->create([
        'target_group' => $targetGroup,
        'contact_id' => Contact::factory()->private(),
    ]);
    $case = app(FundingService::class)->start($lead);

    foreach ($case->steps() as $step) {
        app(FundingService::class)->completeStep($case, $step, []);
    }

    return $case->fresh();
}

function enrolledParticipant(): Participant
{
    $case = caseReadyForEnrollment();
    uploadRequiredDocuments($case);

    return app(FundingService::class)->enroll($case->fresh(), data: ['course_name' => 'Testkurs', 'course_ends_on' => '2027-03-31']);
}

// --- Vorgangsliste nach Phasen ---

it('zeigt übergebene Vorgänge nicht mehr in der Akquise, sondern unter Förderfall', function () {
    $acquisition = Lead::factory()->create(['status' => 'interested']);
    $case = caseReadyForEnrollment();

    Livewire::test(ListLeads::class)
        ->assertCanSeeTableRecords([$acquisition])
        ->assertCanNotSeeTableRecords([$case->lead])
        ->set('activeTab', 'foerderfall')
        ->assertCanSeeTableRecords([$case->lead])
        ->assertCanNotSeeTableRecords([$acquisition]);
});

it('ruft eingeschriebene Teilnehmer nicht mehr aus der Anrufliste an', function () {
    $participant = enrolledParticipant();
    $participant->lead->forceFill(['next_action_at' => null])->saveQuietly();

    expect((new CallList)->queue()->pluck('leads.id'))->not->toContain($participant->lead_id);
});

// --- Pflichtunterlagen und Übergang zum Teilnehmer ---

it('schreibt erst ein, wenn die Pflichtunterlagen hochgeladen sind', function () {
    $case = caseReadyForEnrollment();

    expect(array_keys($case->missingDocuments()))->toBe(['funding_voucher', 'contract'])
        ->and(fn () => $this->funding->enroll($case))->toThrow(ValidationException::class, 'Bildungsgutschein');

    uploadRequiredDocuments($case);

    expect($case->missingDocuments())->toBe([]);
});

it('legt bei der Einschreibung die Teilnehmerakte an und hakt die Bestätigung des Kostenträgers ab', function () {
    $case = caseReadyForEnrollment();
    uploadRequiredDocuments($case);

    $participant = $this->funding->enroll($case->fresh(), data: ['course_name' => 'KI im Beruf', 'birth_date' => '1990-01-02', 'street' => 'Rheinstraße 1', 'postal_code' => '55116', 'city' => 'Mainz']);

    expect($participant)->not->toBeNull()
        ->and($participant->number)->toBe('TN-2026-001')
        ->and($participant->contact_id)->toBe($case->lead->contact_id)
        ->and($participant->course_name)->toBe('KI im Beruf')
        ->and($participant->birth_date->format('d.m.Y'))->toBe('02.01.1990')
        ->and($participant->checks()->whereHas('item', fn ($q) => $q->where('key', 'funder_confirmed'))->exists())->toBeTrue()
        ->and($case->lead->fresh()->status)->toBe('enrolled');
});

it('führt im Förderfall über „Einschreibung bestätigt“ direkt in die Akte', function () {
    $case = caseReadyForEnrollment();
    uploadRequiredDocuments($case);

    Livewire::test(ViewFundingCase::class, ['record' => $case->id])
        ->callAction('enroll', ['course_name' => 'Testkurs'])
        ->assertRedirect();

    expect(Participant::where('funding_case_id', $case->id)->value('course_name'))->toBe('Testkurs');
});

it('macht den Betrieb beim Förderweg § 82 zum Firmenkunden und legt Beschäftigte einzeln an', function () {
    $lead = Lead::factory()->create(['target_group' => 'C', 'status' => 'interested']);
    $case = $this->funding->start($lead);
    foreach ($case->steps() as $step) {
        $this->funding->completeStep($case, $step, []);
    }
    uploadRequiredDocuments($case);

    expect($this->funding->enroll($case->fresh()))->toBeNull()
        ->and($lead->organization->fresh()->customer_since)->not->toBeNull();

    Livewire::test(ViewFundingCase::class, ['record' => $case->id])
        ->callAction('addEmployee', ['first_name' => 'Erika', 'last_name' => 'Muster', 'course_name' => 'Excel kompakt']);

    $participant = Participant::sole();
    expect($participant->contact->organization_id)->toBe($lead->organization_id)
        ->and($participant->displayName())->toContain('Muster');
});

// --- Checkliste, Abschluss, Verbleib ---

it('hakt Punkte der Checkliste mit Dokument ab, Eintrittsmeldung setzt „im Kurs“', function () {
    $participant = enrolledParticipant();
    $entry = ParticipantChecklistItem::where('key', 'entry_report')->first();

    $check = app(ParticipantService::class)->completeCheck($participant, $entry, ['done_on' => '2026-10-05', 'file' => fakePdf('eintritt.pdf')]);

    expect($check->document->participant_id)->toBe($participant->id)
        ->and($check->document->category)->toBe('funder_correspondence')
        ->and($participant->fresh()->state)->toBe('active')
        ->and(fn () => app(ParticipantService::class)->completeCheck($participant, $entry, []))->toThrow(ValidationException::class);

    // Fehlzeitenmeldungen dürfen mehrfach vorkommen.
    $absence = ParticipantChecklistItem::where('key', 'absence')->first();
    app(ParticipantService::class)->completeCheck($participant, $absence, []);
    app(ParticipantService::class)->completeCheck($participant, $absence, []);
    expect($participant->checks()->where('participant_checklist_item_id', $absence->id)->count())->toBe(2);
});

it('setzt nach dem Abschluss die Wiedervorlage zur Verbleibserhebung sechs Monate später', function () {
    $participant = enrolledParticipant();

    Livewire::test(ViewParticipant::class, ['record' => $participant->id])
        ->callAction('finish', ['outcome' => 'completed', 'left_on' => '2027-03-31'])
        ->assertHasNoActionErrors();

    expect($participant->fresh())->state->toBe('completed')
        ->and($participant->fresh()->follow_up_on->format('d.m.Y'))->toBe('30.09.2027')
        ->and($participant->lead->fresh()->next_action_at->format('d.m.Y'))->toBe('30.09.2027');

    app(ParticipantService::class)->recordPlacement($participant->fresh(), 'employed', '2027-10-01');

    expect($participant->fresh())->placement_status->toBe('employed')->follow_up_on->toBeNull()
        ->and($participant->lead->fresh()->next_action_at)->toBeNull();
});

it('zeigt die Akte mit Checkliste, Dokumenten und Hinweis auf fehlende Vertragsdaten', function () {
    $participant = enrolledParticipant();

    Livewire::test(ViewParticipant::class, ['record' => $participant->id])
        ->assertSee('Checkliste')
        ->assertSee('Schulungsvertrag A-16 unterschrieben')
        ->assertSee('Für den Vertrag fehlen noch')
        ->assertSee('Geburtsdatum');

    Livewire::test(ListParticipants::class)->assertCanSeeTableRecords([$participant]);
});

it('lässt den Vertrieb die Teilnehmerakte führen, Gesundheitsangaben aber nicht sehen', function () {
    $participant = enrolledParticipant();
    $participant->contact->update(['health_consent_at' => today(), 'health_consent_proof' => 'Erstgespräch (Test)']);
    $participant->update(['accommodation_notes' => 'Verlängerte Prüfungszeit (Test)']);
    $health = $this->documents->store($participant->lead, fakePdf(), ['category' => 'health'], $participant);

    $staff = loginAs('staff');

    $this->get(ParticipantResource::getUrl('view', ['record' => $participant]))
        ->assertOk()
        ->assertSee('Checkliste')
        ->assertDontSee('Gesundheitsangaben')
        ->assertDontSee('Verlängerte Prüfungszeit');

    expect($health->isVisibleTo($staff))->toBeFalse();
    $this->get(route('filament.crm.documents.show', $health))->assertForbidden();
});

// --- Dokumente ---

it('speichert Dokumente verschlüsselt und protokolliert jeden Abruf', function () {
    $lead = Lead::factory()->create();
    $document = $this->documents->store($lead, fakePdf('gutschein.pdf'), ['category' => 'funding_voucher', 'title' => 'Bildungsgutschein Muster']);

    $raw = Storage::disk('documents')->get($document->path);
    expect($raw)->not->toContain('%PDF')
        ->and($document->mime_type)->toBe('application/pdf');

    $this->get(route('filament.crm.documents.show', $document))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(AuditLog::where('log_name', 'documents')->where('event', 'downloaded')->where('subject_id', $document->id)->exists())->toBeTrue();
});

it('lädt am Vorgang hoch und zeigt die Liste', function () {
    $lead = Lead::factory()->create();

    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->callTableAction('uploadDocument', data: ['file' => fakePdfUpload('angebot.pdf'), 'category' => 'offer', 'title' => 'Angebot Muster'])
        ->assertHasNoTableActionErrors()
        ->assertSee('Angebot Muster');

    expect(Document::where('lead_id', $lead->id)->value('category'))->toBe('offer');
});

it('lehnt unerlaubte Dateitypen ab', function () {
    $lead = Lead::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'exe');
    file_put_contents($path, "MZ\x90\x00 kein Dokument");

    expect(fn () => $this->documents->store($lead, new UploadedFile($path, 'rechnung.pdf.exe', null, null, true), ['category' => 'other']))
        ->toThrow(ValidationException::class, 'Dateityp');
});

it('zeigt Gesundheitsangaben nur der Verwaltung und nur mit Einwilligung', function () {
    $lead = Lead::factory()->inboundPrivate()->create(['contact_id' => Contact::factory()->private()]);

    expect(fn () => $this->documents->store($lead, fakePdf(), ['category' => 'health']))->toThrow(ValidationException::class, 'Einwilligung');

    $lead->contact->update(['health_consent_at' => today(), 'health_consent_proof' => 'Erstgespräch (Test)']);
    $document = $this->documents->store($lead->fresh(), fakePdf(), ['category' => 'health']);

    $staff = loginAs('staff');
    expect($document->isVisibleTo($staff))->toBeFalse();
    $this->get(route('filament.crm.documents.show', $document))->assertForbidden();
});

it('löscht Dokumente nur durch die Verwaltung oder am selben Tag durch die hochladende Person', function () {
    $staff = loginAs('staff');
    $lead = Lead::factory()->create();
    $document = $this->documents->store($lead, fakePdf(), ['category' => 'other'], user: $staff);

    expect($document->isDeletableBy($staff))->toBeTrue();
    $this->travel(1)->days();
    expect($document->fresh()->isDeletableBy($staff))->toBeFalse()
        ->and($document->fresh()->isDeletableBy($this->user))->toBeTrue();

    $this->documents->delete($document);
    expect(Storage::disk('documents')->exists($document->path))->toBeFalse();
});

it('gibt die Akte als ZIP mit PDF-Übersicht und allen Dokumenten aus', function () {
    $participant = enrolledParticipant();

    $path = app(ParticipantArchive::class)->build($participant);
    $zip = new ZipArchive;
    $zip->open($path);
    $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));

    expect($names)->toContain('00_Uebersicht_'.$participant->number.'.pdf')
        ->and($names->filter(fn ($n) => str_starts_with($n, 'Dokumente/')))->toHaveCount(2)
        ->and(substr($zip->getFromName('00_Uebersicht_'.$participant->number.'.pdf'), 0, 4))->toBe('%PDF');

    expect(AuditLog::where('event', 'zip_export')->where('subject_id', $participant->id)->exists())->toBeTrue();
});

// --- Löschfristen ---

it('löscht Teilnehmerakte samt Dokumenten zehn Jahre ab Jahresende nach Ende der Maßnahme', function () {
    $participant = enrolledParticipant();
    app(ParticipantService::class)->finish($participant, 'completed', '2027-03-31');
    $paths = Document::where('lead_id', $participant->lead_id)->pluck('path');

    // Maßnahme endete 2027: Frist läuft ab 31.12.2027 zehn Jahre, gelöscht ab 01.01.2038.
    expect($participant->fresh()->retentionEndsOn()->format('d.m.Y'))->toBe('31.12.2037');

    $this->travelTo(now()->setDate(2037, 12, 30));
    app(RetentionService::class)->run();
    expect(Participant::find($participant->id))->not->toBeNull();

    $this->travelTo(now()->setDate(2038, 1, 2));
    app(RetentionService::class)->run();

    expect(Participant::find($participant->id))->toBeNull()
        ->and(Lead::find($participant->lead_id))->toBeNull()
        ->and(Document::count())->toBe(0)
        ->and($paths->every(fn ($path) => ! Storage::disk('documents')->exists($path)))->toBeTrue();
});

it('löscht die Dateien mit, wenn ein Interessent nach der Frist gelöscht wird', function () {
    $lead = Lead::factory()->create(['last_contact_at' => '2024-01-01']);
    $document = $this->documents->store($lead, fakePdf(), ['category' => 'offer']);

    app(RetentionService::class)->run();

    expect(Lead::find($lead->id))->toBeNull()
        ->and(Storage::disk('documents')->exists($document->path))->toBeFalse();
});
