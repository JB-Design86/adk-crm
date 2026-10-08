<?php

use App\Filament\Pages\CallList;
use App\Filament\Pages\Today;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Livewire\CallbackReminder;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeadExportService;
use App\Services\LeadStatusService;
use App\Support\Spreadsheet;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    // Mittwoch, 07.10.2026, 10:00 Uhr. Zwei Arbeitstage später ist Freitag, der 09.10.2026.
    $this->travelTo(now()->setDate(2026, 10, 7)->setTime(10, 0));
    $this->user = loginAs('staff');
    $this->service = app(LeadStatusService::class);
});

function callbackLead(?string $date, ?string $time = null, array $attributes = []): Lead
{
    return Lead::factory()->create([
        'status' => 'not_reached',
        'next_action_at' => $date,
        'next_action_time' => $time,
        ...$attributes,
    ]);
}

/** Titel der seit der letzten Abfrage gesendeten Hinweise (Filament legt sie in der Sitzung ab). */
function sentNotifications(): array
{
    return session()->pull('filament.claimed_notifications') ?? session()->pull('filament.notifications') ?? [];
}

describe('Status setzen', function () {
    it('speichert ein geändertes Wiedervorlagedatum mit Uhrzeit', function () {
        $lead = Lead::factory()->create();

        $this->service->apply($lead, 'not_reached', ['next_action_at' => '2026-10-08', 'next_action_time' => '07:00'], asCall: true);

        expect($lead->fresh())
            ->next_action_at->toDateString()->toBe('2026-10-08')
            ->next_action_time->toBe('07:00')
            ->and($lead->fresh()->nextActionLabel())->toBe('08.10.2026, 07:00 Uhr')
            ->and($lead->activities()->first()->body)->toBe('Rückruf am 08.10.2026, 07:00 Uhr');
    });

    it('nimmt ohne Datum weiter die zwei Arbeitstage, auch mit Uhrzeit', function () {
        $withTime = Lead::factory()->create();
        $without = Lead::factory()->create();

        $this->service->apply($withTime, 'not_reached', ['next_action_time' => '07:30'], asCall: true);
        $this->service->apply($without, 'not_reached', ['next_action_at' => null], asCall: true);

        expect($withTime->fresh())->next_action_at->toDateString()->toBe('2026-10-09')->next_action_time->toBe('07:30')
            ->and($without->fresh())->next_action_at->toDateString()->toBe('2026-10-09')->next_action_time->toBeNull();
    });

    it('lehnt ein Datum in der Vergangenheit und eine falsche Uhrzeit ab', function (array $data, string $field) {
        $lead = Lead::factory()->create();

        try {
            $this->service->apply($lead, 'not_reached', $data, asCall: true);
            $this->fail('Keine ValidationException');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field);
        }

        expect($lead->fresh())->status->toBe('new')->call_attempts->toBe(0);
    })->with([
        'Datum gestern' => [['next_action_at' => '2026-10-06'], 'next_action_at'],
        'Uhrzeit 25:00' => [['next_action_time' => '25:00'], 'next_action_time'],
    ]);

    it('setzt beim Termin und beim Schließen keine Uhrzeit zur Wiedervorlage', function () {
        $appointment = callbackLead('2026-10-08', '07:00');
        $closed = callbackLead('2026-10-08', '07:00');

        $this->service->apply($appointment, 'appointment', [
            'appointment' => ['date' => '2026-10-08', 'time' => '11:00', 'type' => 'phone'],
            'next_action_time' => '07:00',
        ]);
        $this->service->apply($closed, 'no_interest', ['next_action_time' => '07:00']);

        expect($appointment->fresh()->next_action_time)->toBeNull()
            ->and($closed->fresh())->next_action_at->toBeNull()->next_action_time->toBeNull();
    });

    it('löscht die Uhrzeit, wenn ein neuer Status am selben Tag keine Uhrzeit mitbringt', function () {
        $lead = callbackLead('2026-10-09', '07:00');

        $this->service->apply($lead, 'interested', ['next_action_at' => '2026-10-09']);

        expect($lead->fresh())->next_action_at->toDateString()->toBe('2026-10-09')->next_action_time->toBeNull();
    });

    it('schlägt im Dialog das Datum vor und speichert die Uhrzeit', function () {
        $lead = Lead::factory()->create();

        Livewire::test(ViewLead::class, ['record' => $lead->id])
            ->mountAction('setStatus')
            ->setActionData(['status' => 'not_reached'])
            ->assertActionDataSet(['next_action_at' => '2026-10-09'])
            ->setActionData(['next_action_at' => '2026-10-08', 'next_action_time' => '07:00'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        expect($lead->fresh())->status->toBe('not_reached')
            ->next_action_at->toDateString()->toBe('2026-10-08')
            ->next_action_time->toBe('07:00');
    });
});

describe('Uhrzeit gehört zum Datum', function () {
    it('löscht die Uhrzeit, wenn sich nur das Datum ändert', function () {
        $moved = callbackLead('2026-10-08', '07:00');
        $moved->update(['next_action_at' => '2026-10-12']);

        $rested = callbackLead('2026-10-08', '07:00');
        $this->service->rest($rested);

        $cleared = callbackLead('2026-10-08', '07:00');
        $cleared->update(['next_action_at' => null, 'next_action_time' => '07:00']);

        expect($moved->fresh())->next_action_at->toDateString()->toBe('2026-10-12')->next_action_time->toBeNull()
            ->and($rested->fresh()->next_action_time)->toBeNull()
            ->and($cleared->fresh()->next_action_time)->toBeNull();
    });

    it('behält die Uhrzeit, wenn sie mitgesetzt wird oder das Datum gleich bleibt', function () {
        $moved = callbackLead('2026-10-08', '07:00');
        $moved->update(['next_action_at' => '2026-10-12', 'next_action_time' => '07:00']);

        $other = callbackLead('2026-10-08', '07:00');
        $other->update(['next_action_at' => '2026-10-08', 'call_attempts' => 2]);

        expect($moved->fresh())->next_action_at->toDateString()->toBe('2026-10-12')->next_action_time->toBe('07:00')
            ->and($other->fresh()->next_action_time)->toBe('07:00');
    });

    it('speichert die Uhrzeit im Formular „Bearbeiten“', function () {
        $lead = Lead::factory()->create();

        Livewire::test(EditLead::class, ['record' => $lead->id])
            ->fillForm(['next_action_at' => '2026-10-08', 'next_action_time' => '07:00'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($lead->fresh())->next_action_at->toDateString()->toBe('2026-10-08')->next_action_time->toBe('07:00');

        $this->get(LeadResource::getUrl('view', ['record' => $lead]))->assertOk()->assertSee('08.10.2026, 07:00 Uhr');
    });

    it('nimmt die Uhrzeit in den Export auf', function () {
        $user = loginAs('admin');
        callbackLead('2026-10-08', '07:00');
        callbackLead('2026-10-09');

        $path = app(LeadExportService::class)->export(Lead::query()->orderBy('id'), 'csv', [], $user);
        $rows = iterator_to_array(Spreadsheet::rows($path), false);
        $column = array_search('Wiedervorlage', LeadExportService::HEADER, true);

        expect(array_column(array_slice($rows, 1), $column))->toBe(['08.10.2026 07:00', '09.10.2026']);
    });
});

describe('Heute und Anrufliste', function () {
    it('zeigt in „Heute“ fällige Rückrufe nach den neuen Anfragen zuerst, am selben Tag Uhrzeit vor ohne', function () {
        $inbound = Lead::factory()->inboundPrivate()->create();
        $plainToday = callbackLead('2026-10-07');
        $later = callbackLead('2026-10-07', '14:00');
        $overdue = callbackLead('2026-10-05');
        $dueToday = callbackLead('2026-10-07', '08:00');
        $dueYesterday = callbackLead('2026-10-06', '15:00');

        Livewire::test(Today::class)
            ->assertCanSeeTableRecords([$inbound, $dueYesterday, $dueToday, $overdue, $later, $plainToday], inOrder: true)
            ->assertSee('07.10.2026, 08:00 Uhr')
            ->assertSee('Rückruf jetzt fällig')
            ->assertSee('Rückruf um 14:00 Uhr')
            ->assertSee('überfällig');
    });

    it('bietet einen Rückruf in der Anrufliste erst zur Uhrzeit an, dann vor Priorität A', function () {
        $organization = fn (string $priority) => Organization::factory()->state(['priority' => $priority]);
        $a = callbackLead('2026-10-07', attributes: ['organization_id' => $organization('A')]);
        $at14 = callbackLead('2026-10-07', '14:00', ['organization_id' => $organization('C')]);
        $at930 = callbackLead('2026-10-07', '09:30', ['organization_id' => $organization('B')]);

        expect((new CallList)->queue()->pluck('leads.id')->all())->toBe([$at930->id, $a->id]);

        $this->travelTo(now()->setTime(14, 0));

        expect((new CallList)->queue()->pluck('leads.id')->all())->toBe([$at930->id, $at14->id, $a->id]);

        Livewire::test(CallList::class)
            ->assertSet('leadId', $at930->id)
            ->assertSee('Rückruf 09:30 Uhr')
            ->assertSee('07.10.2026, 09:30 Uhr');
    });

    it('schlägt in der Anrufliste bei Taste 1 das Datum vor und speichert die Uhrzeit', function () {
        $lead = Lead::factory()->create(['organization_id' => Organization::factory()->state(['priority' => 'A'])]);

        Livewire::test(CallList::class)
            ->call('selectKey', '1')
            ->assertSet('nextActionAt', '2026-10-09')
            ->assertSee('Vorschlag: in 2 Arbeitstagen')
            ->set('nextActionAt', '2026-10-08')
            ->set('nextActionTime', '07:00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('nextActionTime', null);

        expect($lead->fresh())->status->toBe('not_reached')
            ->next_action_at->toDateString()->toBe('2026-10-08')
            ->next_action_time->toBe('07:00');
    });
});

describe('Erinnerung', function () {
    it('steht auf jeder Seite des CRM', function () {
        $this->get('/')->assertOk()->assertSeeLivewire(CallbackReminder::class);
    });

    it('erinnert einmal je Anmeldung an fällige Rückrufe, nur an eigene und nicht zugewiesene', function () {
        $mine = callbackLead('2026-10-07', '09:30', ['assigned_to' => $this->user->id]);
        $unassigned = callbackLead('2026-10-06', '16:00');
        callbackLead('2026-10-07', '09:00', ['assigned_to' => User::factory()]);
        callbackLead('2026-10-07', '09:00', ['assigned_to' => $this->user->id, 'closed_at' => now()]);
        callbackLead('2026-10-07', null, ['assigned_to' => $this->user->id]);
        $later = callbackLead('2026-10-07', '14:00', ['assigned_to' => $this->user->id]);

        $component = Livewire::test(CallbackReminder::class)->call('check');

        $notifications = collect(sentNotifications())->keyBy('title');
        expect($notifications->keys()->all())->toBe(['Rückruf fällig: '.$unassigned->displayName(), 'Rückruf fällig: '.$mine->displayName()])
            ->and($notifications['Rückruf fällig: '.$mine->displayName()])
            ->body->toBe('09:30 Uhr · '.$mine->phoneDisplay())
            ->status->toBe('warning')
            ->and($notifications['Rückruf fällig: '.$mine->displayName()]['actions'][0])
            ->label->toBe('Vorgang öffnen')
            ->url->toBe(LeadResource::getUrl('view', ['record' => $mine]))
            ->and($notifications['Rückruf fällig: '.$unassigned->displayName()]['body'])->toStartWith('06.10.2026, 16:00 Uhr');

        $component->call('check');
        expect(sentNotifications())->toBe([]);

        $this->travelTo(now()->setTime(14, 0));
        $component->call('check');
        expect(collect(sentNotifications())->pluck('title')->all())->toBe(['Rückruf fällig: '.$later->displayName()]);
    });

    it('erinnert erneut, wenn der Rückruf auf eine neue Uhrzeit gelegt wird', function () {
        $lead = callbackLead('2026-10-07', '09:30');

        $component = Livewire::test(CallbackReminder::class)->call('check');
        expect(sentNotifications())->toHaveCount(1);

        $lead->update(['next_action_time' => '09:45']);
        $component->call('check');
        expect(sentNotifications())->toHaveCount(1);
    });
});

it('ändert die Wiedervorlage auf der Vorgangsseite, ohne den Status zu ändern, und protokolliert das', function () {
    $lead = Lead::factory()->create(['status' => 'documents_sent']);
    $lead->forceFill(['next_action_at' => now()->addDays(13)->toDateString(), 'next_action_time' => '09:00'])->save();

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->assertActionVisible('changeFollowUp')
        ->callAction('changeFollowUp', ['next_action_at' => now()->addDays(6)->toDateString(), 'next_action_time' => '09:00', 'note' => 'Tippfehler'])
        ->assertHasNoActionErrors();

    $lead->refresh();
    expect($lead->status)->toBe('documents_sent')
        ->and($lead->next_action_at->toDateString())->toBe(now()->addDays(6)->toDateString())
        ->and($lead->next_action_time)->toBe('09:00');

    $activity = $lead->activities()->latest('id')->first();
    expect($activity->type)->toBe('note')
        ->and($activity->body)->toContain('Wiedervorlage geändert: '.now()->addDays(13)->format('d.m.Y').', 09:00 Uhr → '.now()->addDays(6)->format('d.m.Y').', 09:00 Uhr')
        ->and($activity->body)->toContain('Tippfehler');
});
