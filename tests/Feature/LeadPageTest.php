<?php

use App\Filament\Pages\Today;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Models\Activity;
use App\Models\BlocklistEntry;
use App\Models\Lead;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 24)->setTime(10, 0));
    $this->user = loginAs('staff');
});

it('setzt auf der Vorgangsseite den Status über den Dialog', function () {
    $lead = Lead::factory()->create();

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->mountAction('setStatus')
        ->assertActionMounted('setStatus')
        ->setActionData(['status' => 'interested', 'next_action_at' => '2026-10-02', 'note' => 'Rückruf'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($lead->fresh())->status->toBe('interested')->next_action_at->toDateString()->toBe('2026-10-02');

    // „Kontakt per: Anruf“ ist vorbelegt und zählt in der Auswertung als Anruf, „E-Mail“ nur als Statuswechsel.
    expect($lead->activities()->sole())->type->toBe('call')->outcome->toBe('interested');

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('setStatus', ['contact_via' => 'email', 'status' => 'later', 'next_action_at' => '2026-11-02'])
        ->assertHasNoActionErrors();

    expect($lead->activities()->where('outcome', 'later')->sole())->type->toBe('status_change')->body->toStartWith('per E-Mail');
});

it('verlangt im Dialog die Bestätigung des Werbewiderspruchs', function () {
    $lead = Lead::factory()->create();

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('setStatus', ['status' => 'objection'])
        ->assertHasActionErrors(['confirmed']);

    expect(BlocklistEntry::count())->toBe(0);

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('setStatus', ['status' => 'objection', 'confirmed' => true])
        ->assertHasNoActionErrors();

    expect($lead->fresh()->status)->toBe('objection')->and(BlocklistEntry::count())->toBe(1);
});

it('erfasst Aktivitäten und legt per Cross-Selling einen verknüpften Vorgang für JB IT-Design an', function () {
    $lead = Lead::factory()->create();

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('addActivity', ['type' => 'email', 'body' => 'Unterlagen angefragt'])
        ->assertHasNoActionErrors()
        ->callAction('otherBrand', ['status' => 'interested', 'next_action_at' => '2026-10-20', 'note' => 'Website ist veraltet'])
        ->assertHasNoActionErrors()
        ->assertActionHidden('otherBrand');

    $jb = Lead::where('brand', 'jb')->sole();

    expect(Activity::where('lead_id', $lead->id)->where('type', 'email')->exists())->toBeTrue()
        ->and($jb)->organization_id->toBe($lead->organization_id)->status->toBe('interested')->channel->toBe('cross_selling')
        ->package_lead_id->toBe($lead->id)->next_action_at->toDateString()->toBe('2026-10-20')
        ->and($lead->fresh())->status->toBe('new')->package_lead_id->toBe($jb->id);

    Livewire::test(ViewLead::class, ['record' => $jb->id])->assertSee('ADK-Vorgang');
});

it('setzt in der Heute-Ansicht den Status über die Zeilenaktion', function () {
    $lead = Lead::factory()->create(['next_action_at' => '2026-09-24']);

    Livewire::test(Today::class)
        ->callAction(TestAction::make('setStatus')->table($lead), ['status' => 'no_need'])
        ->assertHasNoActionErrors();

    expect($lead->fresh()->status)->toBe('no_need');
});
