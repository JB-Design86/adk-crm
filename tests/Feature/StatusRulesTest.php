<?php

use App\Models\Activity;
use App\Models\BlocklistEntry;
use App\Models\Contact;
use App\Models\Lead;
use App\Services\LeadStatusService;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // Donnerstag, 24.09.2026
    $this->travelTo(now()->setDate(2026, 9, 24)->setTime(10, 0));
    $this->user = loginAs('staff');
    $this->service = app(LeadStatusService::class);
});

it('legt Vorgänge mit Status Neu an; eingehende Anfragen mit Wiedervorlage heute', function () {
    $cold = Lead::factory()->create();
    $inbound = Lead::factory()->inboundPrivate()->create();

    expect($cold->status)->toBe('new')
        ->and($cold->next_action_at)->toBeNull()
        ->and($inbound->status)->toBe('new')
        ->and($inbound->next_action_at->toDateString())->toBe('2026-09-24')
        ->and($inbound->isNewInbound())->toBeTrue();
});

it('Taste 1: zählt den Versuch und setzt die Wiedervorlage zwei Arbeitstage später', function () {
    $lead = Lead::factory()->create();

    $result = $this->service->apply($lead, 'not_reached', asCall: true);

    expect($lead->fresh()->call_attempts)->toBe(1)
        ->and($lead->fresh()->next_action_at->toDateString())->toBe('2026-09-28')
        ->and($result->suggestRest)->toBeFalse();
});

it('Taste 1: fragt nach dem dritten Versuch, ob der Vorgang ruhen soll', function () {
    $lead = Lead::factory()->create(['call_attempts' => 2]);

    $result = $this->service->apply($lead, 'not_reached', asCall: true);

    expect($lead->fresh()->call_attempts)->toBe(3)->and($result->suggestRest)->toBeTrue();

    $this->service->rest($lead);
    expect($lead->fresh()->next_action_at->toDateString())->toBe('2026-12-24');
});

it('Taste 2 und 5: verlangen ein Wiedervorlagedatum', function (string $status) {
    $lead = Lead::factory()->create();

    expect(fn () => $this->service->apply($lead, $status))->toThrow(ValidationException::class);

    $this->service->apply($lead, $status, ['next_action_at' => '2026-10-15']);

    expect($lead->fresh())
        ->status->toBe($status)
        ->next_action_at->toDateString()->toBe('2026-10-15');
})->with(['interested', 'later']);

it('lehnt ein Wiedervorlagedatum in der Vergangenheit ab', function () {
    $lead = Lead::factory()->create();

    expect(fn () => $this->service->apply($lead, 'interested', ['next_action_at' => '2026-09-01']))
        ->toThrow(ValidationException::class);
});

it('Taste 3: setzt Datenschutzhinweis übermittelt am und die Wiedervorlage fünf Arbeitstage später', function () {
    $lead = Lead::factory()->create(['contact_id' => Contact::factory()]);

    $this->service->apply($lead, 'documents_sent');

    expect($lead->fresh()->contact->privacy_notice_sent_at->toDateString())->toBe('2026-09-24')
        ->and($lead->fresh()->next_action_at->toDateString())->toBe('2026-10-01');
});

it('Taste 3: überschreibt ein vorhandenes Datum des Datenschutzhinweises nicht', function () {
    $lead = Lead::factory()->create(['contact_id' => Contact::factory()->state(['privacy_notice_sent_at' => '2026-08-01'])]);

    $this->service->apply($lead, 'documents_sent');

    expect($lead->fresh()->contact->privacy_notice_sent_at->toDateString())->toBe('2026-08-01');
});

it('Taste 3: legt ohne Kontakt den Empfänger an', function () {
    $lead = Lead::factory()->create();

    expect(fn () => $this->service->apply($lead, 'documents_sent'))->toThrow(ValidationException::class);

    $this->service->apply($lead, 'documents_sent', ['contact' => ['last_name' => 'Muster', 'email' => 'muster@example.org']]);

    expect($lead->fresh()->contact)
        ->last_name->toBe('Muster')
        ->privacy_notice_sent_at->toDateString()->toBe('2026-09-24');
});

it('Taste 4: legt einen Termin an, Wiedervorlage am Termintag', function () {
    $lead = Lead::factory()->create();

    expect(fn () => $this->service->apply($lead, 'appointment', ['appointment' => ['date' => '2026-09-30']]))
        ->toThrow(ValidationException::class);

    $result = $this->service->apply($lead, 'appointment', [
        'appointment' => ['date' => '2026-09-30', 'time' => '14:30', 'type' => 'teams'],
    ]);

    expect($result->appointment)
        ->starts_at->format('Y-m-d H:i')->toBe('2026-09-30 14:30')
        ->type->toBe('teams')
        ->and($lead->fresh()->next_action_at->toDateString())->toBe('2026-09-30');
});

it('Tasten 6, 7 und 9 schließen den Vorgang', function (string $status, array $data) {
    $lead = Lead::factory()->create(['next_action_at' => '2026-09-24']);

    $this->service->apply($lead, $status, $data);

    expect($lead->fresh())
        ->status->toBe($status)
        ->closed_at->not->toBeNull()
        ->next_action_at->toBeNull();
})->with([
    ['no_interest', []],
    ['no_need', []],
    ['wrong_data', ['close_reason' => 'wrong_number']],
]);

it('Taste 9: verlangt einen Grund', function () {
    $lead = Lead::factory()->create();

    expect(fn () => $this->service->apply($lead, 'wrong_data'))->toThrow(ValidationException::class);

    $this->service->apply($lead, 'wrong_data', ['close_reason' => 'company_gone']);
    expect($lead->fresh()->close_reason)->toBe('company_gone');
});

it('Taste 8: verlangt die Sicherheitsabfrage', function () {
    $lead = Lead::factory()->create();

    expect(fn () => $this->service->apply($lead, 'objection'))->toThrow(ValidationException::class);
    expect($lead->fresh()->status)->toBe('new');
    expect(BlocklistEntry::count())->toBe(0);
});

it('Taste 8: schließt den Vorgang und setzt Telefon, E-Mail und Firma auf die Sperrliste', function () {
    $lead = Lead::factory()->create([
        'contact_id' => Contact::factory()->state(['phone_display' => '0171 1234567', 'email' => 'person@example.org']),
    ]);
    $organization = $lead->organization;

    $this->service->apply($lead, 'objection', ['confirmed' => true]);

    expect($lead->fresh())->status->toBe('objection')->closed_at->not->toBeNull();
    expect(BlocklistEntry::matches(phone: $organization->phone_e164))->toBeTrue()
        ->and(BlocklistEntry::matches(email: $organization->email))->toBeTrue()
        ->and(BlocklistEntry::matches(companyName: $organization->name, postalCode: $organization->postal_code))->toBeTrue()
        ->and(BlocklistEntry::matches(phone: '+491711234567'))->toBeTrue()
        ->and(BlocklistEntry::matches(email: 'PERSON@example.org'))->toBeTrue()
        ->and($lead->fresh()->isBlocked())->toBeTrue();
});

it('Taste 0: schaltet Cross-Selling um, fragt nach Wiedervorlage und ändert den Status nicht', function () {
    $lead = Lead::factory()->create(['status' => 'interested', 'next_action_at' => '2026-10-10']);

    expect(fn () => $this->service->toggleCrossSelling($lead))->toThrow(ValidationException::class);

    $this->service->toggleCrossSelling($lead, '2026-11-02');

    expect($lead->fresh())
        ->cross_selling->toBeTrue()
        ->cross_selling_follow_up_at->toDateString()->toBe('2026-11-02')
        ->status->toBe('interested')
        ->next_action_at->toDateString()->toBe('2026-10-10');

    $this->service->toggleCrossSelling($lead->fresh());
    expect($lead->fresh())->cross_selling->toBeFalse()->cross_selling_follow_up_at->toBeNull();
});

it('Übergeben an Förderweg ist setzbar und startet den Förderfall', function () {
    $lead = Lead::factory()->create();

    $this->service->apply($lead, 'handed_over', ['target_group' => 'C']);

    expect($lead->fresh())->status->toBe('handed_over')->closed_at->toBeNull()
        ->and($lead->fresh()->fundingCase)->not->toBeNull();
});

it('erzeugt bei jedem Statuswechsel eine Aktivität mit Benutzer und Zeitpunkt', function () {
    $lead = Lead::factory()->create();

    $this->service->apply($lead, 'not_reached', ['note' => 'Mailbox'], asCall: true);
    $this->service->apply($lead, 'interested', ['next_action_at' => '2026-10-01', 'note' => 'Rückruf erbeten']);

    $activities = Activity::where('lead_id', $lead->id)->orderBy('id')->get();

    expect($activities)->toHaveCount(2)
        ->and($activities[0])->type->toBe('call')->status_from->toBe('new')->status_to->toBe('not_reached')->body->toBe('Mailbox')->user_id->toBe($this->user->id)
        ->and($activities[1])->type->toBe('status_change')->status_from->toBe('not_reached')->status_to->toBe('interested')
        ->and($activities[1]->occurred_at)->not->toBeNull();
});

describe('Anrufsperre für Privatpersonen', function () {
    it('sperrt Anrufe bei Privatpersonen ohne Einwilligung aus der Kaltakquise', function () {
        $lead = Lead::factory()->coldPrivate()->create();

        expect($lead->isCallable())->toBeFalse();
        expect(fn () => $this->service->apply($lead, 'not_reached', asCall: true))->toThrow(ValidationException::class);
    });

    it('erlaubt Anrufe mit eingetragener Einwilligung und merkt die Verwendung', function () {
        $lead = Lead::factory()->coldPrivate()->create([
            'contact_id' => Contact::factory()->private()->withPhoneConsent(),
        ]);

        expect($lead->isCallable())->toBeTrue();
        $this->service->apply($lead, 'not_reached', asCall: true);
        expect($lead->fresh()->contact->phone_consent_last_used_at->toDateString())->toBe('2026-09-24');
    });

    it('erlaubt Anrufe, wenn die Anfrage über einen eingehenden Kanal kam', function () {
        $lead = Lead::factory()->inboundPrivate('phone')->create();

        expect($lead->isCallable())->toBeTrue();
    });

    it('verlangt Datum und Nachweis der Einwilligung', function () {
        $lead = Lead::factory()->coldPrivate()->create([
            'contact_id' => Contact::factory()->private()->state(['phone_consent_at' => '2026-09-01']),
        ]);

        expect($lead->isCallable())->toBeFalse();
    });

    it('sperrt Anrufe bei Einträgen auf der Sperrliste', function () {
        $lead = Lead::factory()->create();
        BlocklistEntry::create(['phone_e164' => $lead->organization->phone_e164, 'reason' => 'Test']);

        expect($lead->fresh()->isCallable())->toBeFalse()
            ->and($lead->fresh()->callBlockReason())->toContain('Sperrliste');
        expect(fn () => $this->service->apply($lead->fresh(), 'interested', ['next_action_at' => '2026-10-01'], asCall: true))
            ->toThrow(ValidationException::class);
    });
});
