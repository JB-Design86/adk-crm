<?php

use App\Filament\Resources\FundingCases\Pages\ViewFundingCase;
use App\Filament\Resources\FundingSteps\Pages\EditFundingStep;
use App\Models\Contact;
use App\Models\FundingCase;
use App\Models\FundingStep;
use App\Models\Lead;
use App\Services\FundingService;
use App\Services\LeadStatusService;
use App\Services\ReportService;
use App\Services\RetentionService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    // Donnerstag, 24.09.2026
    $this->travelTo(now()->setDate(2026, 9, 24)->setTime(10, 0));
    $this->user = loginAs('staff');
    $this->funding = app(FundingService::class);
});

function privateLead(string $targetGroup, array $contact = []): Lead
{
    return Lead::factory()->inboundPrivate()->create([
        'target_group' => $targetGroup,
        'contact_id' => Contact::factory()->private()->state($contact),
    ]);
}

it('legt die Standard-Schritte aus dem Lastenheft an', function () {
    expect(FundingStep::query()->forPathway('voucher')->pluck('name')->first())->toBe('Beratungsgespräch geführt')
        ->and(FundingStep::query()->forPathway('employer')->count())->toBe(6)
        ->and(FundingStep::query()->forPathway('accident')->count())->toBe(9)
        ->and(FundingStep::query()->forPathway('self_payer')->count())->toBe(2);
});

it('startet mit „Übergeben an Förderweg“ einen Förderfall passend zur Zielgruppe', function () {
    $lead = privateLead('A');

    $result = app(LeadStatusService::class)->apply($lead, 'handed_over');

    expect($result->fundingCase)->not->toBeNull()
        ->and($result->fundingCase->pathway)->toBe('voucher')
        ->and($lead->fresh()->status)->toBe('handed_over')
        ->and($lead->fresh()->next_action_at->toDateString())->toBe('2026-09-24')
        ->and($result->fundingCase->currentStep()->name)->toBe('Beratungsgespräch geführt');
});

it('verlangt bei offener Zielgruppe die Auswahl der Zielgruppe', function () {
    $lead = Lead::factory()->create(['target_group' => 'company_open']);

    expect(fn () => app(LeadStatusService::class)->apply($lead, 'handed_over'))->toThrow(ValidationException::class);
    expect(FundingCase::count())->toBe(0);

    app(LeadStatusService::class)->apply($lead, 'handed_over', ['target_group' => 'C']);

    expect($lead->fresh()->target_group)->toBe('C')
        ->and($lead->fundingCase->pathway)->toBe('employer');
});

it('startet den Förderweg E nur mit Einwilligung zu Gesundheitsangaben', function () {
    $without = privateLead('E');
    expect(fn () => $this->funding->start($without))->toThrow(ValidationException::class);

    $with = privateLead('E', ['health_consent_at' => '2026-09-20', 'health_consent_proof' => 'Formular']);
    expect($this->funding->start($with)->pathway)->toBe('accident');
});

it('setzt nach jedem Schritt die Wiedervorlage nach der Frist des Schritts', function () {
    $case = $this->funding->start(privateLead('A'));
    $first = $case->currentStep(); // Beratungsgespräch, 2 Arbeitstage

    $this->funding->completeStep($case, $first, ['completed_on' => '2026-09-24', 'party' => 'adk', 'note' => 'gut gelaufen']);

    expect($case->lead->fresh()->next_action_at->toDateString())->toBe('2026-09-28')
        ->and($case->fresh()->currentStep()->name)->toBe('Eignung festgestellt (A-06)')
        ->and($case->lead->activities()->where('type', 'funding_step')->count())->toBe(2);
});

it('rechnet bei E die Fristen in Kalendertagen (§ 14 SGB IX)', function () {
    $case = $this->funding->start(privateLead('E', ['health_consent_at' => '2026-09-20', 'health_consent_proof' => 'Formular']));
    $steps = $case->steps();

    $this->funding->completeStep($case, $steps[0], ['completed_on' => '2026-09-22']);
    $this->funding->completeStep($case, $steps[1], ['completed_on' => '2026-09-24']); // formloser Antrag, 14 Kalendertage

    expect($case->lead->fresh()->next_action_at->toDateString())->toBe('2026-10-08');
});

it('lässt abgelehnt nur bei Bewilligungsschritten zu', function () {
    $case = $this->funding->start(privateLead('A'));
    $steps = $case->steps();

    expect(fn () => $this->funding->completeStep($case, $steps[0], ['result' => 'rejected']))->toThrow(ValidationException::class);

    $approval = $steps->firstWhere('can_fail', true);
    $record = $this->funding->completeStep($case, $approval, ['result' => 'rejected']);

    expect($record->result)->toBe('rejected')
        ->and($case->lead->fresh()->next_action_at->toDateString())->toBe('2026-09-24');
});

it('lehnt Schritte in der Zukunft und doppelte Einträge ab', function () {
    $case = $this->funding->start(privateLead('B'));
    $step = $case->currentStep();

    expect(fn () => $this->funding->completeStep($case, $step, ['completed_on' => '2026-09-30']))->toThrow(ValidationException::class);

    $this->funding->completeStep($case, $step, []);
    expect(fn () => $this->funding->completeStep($case, $step, []))->toThrow(ValidationException::class);
});

it('bestätigt die Einschreibung erst, wenn alle Schritte erledigt sind', function () {
    $case = $this->funding->start(privateLead('self_payer'));

    expect(fn () => $this->funding->enroll($case))->toThrow(ValidationException::class);

    foreach ($case->steps() as $step) {
        $this->funding->completeStep($case, $step, []);
    }

    $this->funding->enroll($case->fresh());

    expect($case->fresh()->state)->toBe('enrolled')
        ->and($case->lead->fresh())->status->toBe('enrolled')->contracted_at->not->toBeNull()->next_action_at->toBeNull();
});

it('nimmt eingeschriebene Teilnehmer aus der Löschfrist für Interessenten heraus', function () {
    $case = $this->funding->start(privateLead('self_payer'));
    foreach ($case->steps() as $step) {
        $this->funding->completeStep($case, $step, []);
    }
    $this->funding->enroll($case->fresh());
    $case->lead->forceFill(['last_contact_at' => '2025-01-01'])->saveQuietly();

    app(RetentionService::class)->run();

    expect(Lead::find($case->lead_id))->not->toBeNull()->and(FundingCase::find($case->id))->not->toBeNull();
});

it('löscht Förderfälle ohne Vertrag mit dem Vorgang', function () {
    $case = $this->funding->start(privateLead('A'));
    $this->funding->completeStep($case, $case->currentStep(), []);
    $case->lead->forceFill(['last_contact_at' => '2026-01-01'])->saveQuietly();

    app(RetentionService::class)->run();

    expect(FundingCase::find($case->id))->toBeNull();
});

it('bricht den Förderweg ab und setzt den Vorgang auf Wiedervorlage', function () {
    $case = $this->funding->start(privateLead('A'));

    $this->funding->cancel($case, 'Gutschein nicht in Aussicht', 'later', '2026-11-02');

    expect($case->fresh()->state)->toBe('cancelled')
        ->and($case->lead->fresh())->status->toBe('later')->next_action_at->toDateString()->toBe('2026-11-02');
});

it('nimmt einen Schritt zurück und rechnet die Wiedervorlage neu', function () {
    $case = $this->funding->start(privateLead('A'));
    $record = $this->funding->completeStep($case, $case->currentStep(), []);

    $this->funding->undoStep($record);

    expect($case->fresh()->currentStep()->name)->toBe('Beratungsgespräch geführt')
        ->and($case->lead->fresh()->next_action_at->toDateString())->toBe('2026-09-24');
});

it('zeigt den Förderfall mit Erklärtext zum aktuellen Schritt und erledigt Schritte über den Dialog', function () {
    $case = $this->funding->start(privateLead('A'));
    $case->currentStep()->update(['instructions' => 'Beratungsbogen A-05 ausfüllen und ablegen.']);

    $this->get("/foerderfaelle/{$case->id}")
        ->assertOk()
        ->assertSee('Was ist jetzt von unserer Seite zu tun?')
        ->assertSee('Beratungsbogen A-05 ausfüllen und ablegen.');

    Livewire::test(ViewFundingCase::class, ['record' => $case->id])
        ->callAction('completeStep', ['completed_on' => '2026-09-24', 'party' => 'adk', 'result' => 'done'])
        ->assertHasNoActionErrors();

    expect($case->fresh()->progressLabel())->toBe('1 von 9');
    $this->get('/foerderfaelle')->assertOk()->assertSee('Eignung festgestellt (A-06)');
});

it('lässt nur die Verwaltung Förderweg-Schritte pflegen', function () {
    $this->get('/foerderweg-schritte')->assertForbidden();

    loginAs('admin');
    $step = FundingStep::query()->forPathway('voucher')->first();

    Livewire::test(EditFundingStep::class, ['record' => $step->id])
        ->fillForm(['instructions' => 'Termin anbieten, Beratungsbogen vorbereiten.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($step->fresh()->instructions)->toBe('Termin anbieten, Beratungsbogen vorbereiten.');
});

it('zählt den Förderweg in der Auswertung', function () {
    $case = $this->funding->start(privateLead('A'));
    $this->funding->completeStep($case, $case->currentStep(), []);

    $voucher = collect(ReportService::forPeriod('2026-09-21', '2026-09-27')->fundingFunnel())->firstWhere('pathway', 'voucher');

    expect($voucher['started'])->toBe(1)
        ->and($voucher['steps'][0])->toBe(['name' => 'Beratungsgespräch geführt', 'done' => 1, 'rejected' => 0]);
});
