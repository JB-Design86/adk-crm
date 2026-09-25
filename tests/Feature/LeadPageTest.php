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

it('erfasst Aktivitäten und Cross-Selling auf der Vorgangsseite', function () {
    $lead = Lead::factory()->create();

    Livewire::test(ViewLead::class, ['record' => $lead->id])
        ->callAction('addActivity', ['type' => 'email', 'body' => 'Unterlagen angefragt'])
        ->assertHasNoActionErrors()
        ->callAction('crossSelling', ['follow_up_at' => '2026-10-20'])
        ->assertHasNoActionErrors();

    expect(Activity::where('lead_id', $lead->id)->where('type', 'email')->exists())->toBeTrue()
        ->and($lead->fresh()->cross_selling)->toBeTrue();
});

it('setzt in der Heute-Ansicht den Status über die Zeilenaktion', function () {
    $lead = Lead::factory()->create(['next_action_at' => '2026-09-24']);

    Livewire::test(Today::class)
        ->callAction(TestAction::make('setStatus')->table($lead), ['status' => 'no_need'])
        ->assertHasNoActionErrors();

    expect($lead->fresh()->status)->toBe('no_need');
});
