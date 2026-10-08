<?php

use App\Filament\Pages\Calendar;
use App\Filament\Pages\Today;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Appointment;
use App\Models\ImportLog;
use App\Models\Lead;
use App\Models\Organization;
use App\Services\LeadStatusService;
use App\Services\ReportService;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 24)->setTime(10, 0));
    $this->user = loginAs('staff');
});

it('zeigt heute Fälliges und Überfälliges, neue eingehende Anfragen zuerst', function () {
    $due = Lead::factory()->create(['next_action_at' => '2026-09-24', 'status' => 'interested']);
    $overdue = Lead::factory()->create(['next_action_at' => '2026-09-20', 'status' => 'not_reached']);
    $future = Lead::factory()->create(['next_action_at' => '2026-10-01', 'status' => 'later']);
    $closed = Lead::factory()->create(['next_action_at' => '2026-09-24', 'status' => 'no_interest', 'closed_at' => now()]);
    $inbound = Lead::factory()->inboundPrivate()->create();

    Livewire::test(Today::class)
        ->assertCanSeeTableRecords([$inbound, $overdue, $due], inOrder: true)
        ->assertCanNotSeeTableRecords([$future, $closed])
        ->assertSee('überfällig');
});

it('zeigt einen Termin am Termintag in „Heute“', function () {
    $lead = Lead::factory()->create();
    app(LeadStatusService::class)->apply($lead, 'appointment', ['appointment' => ['date' => '2026-09-30', 'time' => '10:00', 'type' => 'phone']]);

    Livewire::test(Today::class)->assertCanNotSeeTableRecords([$lead]);

    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(8, 0));

    Livewire::test(Today::class)
        ->assertCanSeeTableRecords([$lead])
        ->assertSee('10:00');
});

it('filtert „nur meine“', function () {
    $mine = Lead::factory()->create(['next_action_at' => '2026-09-24', 'assigned_to' => $this->user->id]);
    $other = Lead::factory()->create(['next_action_at' => '2026-09-24']);

    Livewire::test(Today::class)
        ->filterTable('mine', true)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$other]);
});

it('zeigt Termine und Wiedervorlagen im Kalender nach Woche und Tag', function () {
    $lead = Lead::factory()->create(['next_action_at' => '2026-09-25', 'status' => 'interested']);
    $appointmentLead = Lead::factory()->create(['status' => 'appointment']);
    Appointment::create(['lead_id' => $appointmentLead->id, 'user_id' => $this->user->id, 'starts_at' => '2026-09-23 14:00', 'type' => 'teams']);

    Livewire::test(Calendar::class)
        ->assertSee('KW 39')
        ->assertSee($lead->displayName())
        ->assertSee('14:00')
        ->call('showDay', '2026-09-25')
        ->assertSet('mode', 'day')
        ->assertSee($lead->displayName())
        ->assertDontSee('14:00');
});

it('zählt Anrufe, Erreichte, Termine und Unterlagen und bildet Quoten', function () {
    $service = app(LeadStatusService::class);
    $organization = Organization::factory()->create(['industry' => 'Steuerberatung', 'source' => 'Quelle X']);
    $a = Lead::factory()->create(['organization_id' => $organization->id]);
    $b = Lead::factory()->create(['organization_id' => Organization::factory()->state(['industry' => 'Steuerberatung', 'source' => 'Quelle X'])]);
    $c = Lead::factory()->create(['organization_id' => Organization::factory()->state(['industry' => 'Großhandel', 'source' => 'Quelle Y'])]);

    $service->apply($a, 'not_reached', asCall: true);
    $service->apply($a, 'appointment', ['appointment' => ['date' => '2026-09-28', 'time' => '09:00', 'type' => 'phone']], asCall: true);
    $service->apply($b, 'documents_sent', ['contact' => ['last_name' => 'Muster']], asCall: true);
    $service->apply($c, 'wrong_data', ['close_reason' => 'wrong_number'], asCall: true);

    $report = ReportService::forPeriod('2026-09-21', '2026-09-27');

    expect($report->totals())->toBe(['calls' => 4, 'reached' => 2, 'appointments' => 1, 'documents' => 1]);
    expect($report->perDay()['2026-09-24']['calls'])->toBe(4);
    expect($report->perWeek())->toHaveKey('KW 39 / 2026');

    $industries = collect($report->byDimension('industry'))->keyBy('label');
    expect($industries['Steuerberatung'])->calls->toBe(3)->reached->toBe(2)
        ->and($industries['Steuerberatung']['reached_rate'])->toEqualWithDelta(2 / 3, 0.001);

    expect($report->wrongDataBySource())->toBe([['source' => 'Quelle Y', 'wrong' => 1, 'leads' => 1, 'rate' => 1]]);

    $this->get('/auswertung')->assertOk()->assertSee('Quoten je Branche');
});

it('zeigt in den Vorgängen, wann ein Vorgang eingespielt wurde, und filtert nach Leadliste', function () {
    $older = ImportLog::create(['file_name' => 'liste-september.xlsx', 'source' => 'Recherche', 'retrieved_at' => '2026-09-20', 'rows_imported' => 1]);
    $newer = ImportLog::create(['file_name' => 'premium-kunden.xlsx', 'source' => 'Recherche', 'retrieved_at' => '2026-09-24', 'rows_imported' => 2]);
    $old = Lead::factory()->create(['import_log_id' => $older->id, 'created_at' => '2026-09-20 09:00']);
    $premiumA = Lead::factory()->create(['import_log_id' => $newer->id, 'created_at' => '2026-09-24 08:15']);
    $premiumB = Lead::factory()->create(['import_log_id' => $newer->id, 'created_at' => '2026-09-24 08:15']);

    Livewire::test(ListLeads::class)
        ->assertSee('Import: premium-kunden.xlsx')
        ->assertSee('24.09.2026 08:15')
        ->sortTable('created_at', 'desc')
        ->assertCanSeeTableRecords([$premiumB, $premiumA, $old], inOrder: true)
        ->filterTable('import_log_id', [$newer->id])
        ->assertCanSeeTableRecords([$premiumA, $premiumB])
        ->assertCanNotSeeTableRecords([$old]);
});
