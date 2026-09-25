<?php

use App\Filament\Pages\CallList;
use App\Models\BlocklistEntry;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Organization;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 24)->setTime(10, 0));
    $this->user = loginAs('staff');
});

function leadWithPriority(string $priority, ?string $nextActionAt = null): Lead
{
    return Lead::factory()->create([
        'organization_id' => Organization::factory()->state(['priority' => $priority]),
        'next_action_at' => $nextActionAt,
    ]);
}

it('zeigt die Betriebe nach Priorität A, B, C und dann nach ältester Wiedervorlage', function () {
    $c = leadWithPriority('C', '2026-09-01');
    $bNew = leadWithPriority('B', '2026-09-23');
    $bOld = leadWithPriority('B', '2026-09-10');
    $a = leadWithPriority('A', '2026-09-24');
    leadWithPriority('A', '2026-10-15'); // Wiedervorlage in der Zukunft: nicht in der Liste

    $ids = (new CallList)->queue()->pluck('leads.id')->all();

    expect($ids)->toBe([$a->id, $bOld->id, $bNew->id, $c->id]);
});

it('zeigt immer genau einen Betrieb und springt nach dem Speichern zum nächsten', function () {
    $first = leadWithPriority('A');
    $second = leadWithPriority('B');

    Livewire::test(CallList::class)
        ->assertSet('leadId', $first->id)
        ->assertSee($first->organization->name)
        ->assertSee('tel:'.$first->organization->phone_e164)
        ->call('selectKey', '1')
        ->assertSet('status', 'not_reached')
        ->set('note', 'Besetzt')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('leadId', $second->id)
        ->assertSet('note', '');

    expect($first->fresh())->status->toBe('not_reached')->call_attempts->toBe(1);
    expect($first->activities()->first())->type->toBe('call')->body->toBe('Besetzt');
});

it('verlangt bei Taste 2 ein Wiedervorlagedatum', function () {
    $lead = leadWithPriority('A');

    Livewire::test(CallList::class)
        ->call('selectKey', '2')
        ->call('save')
        ->assertHasErrors('next_action_at')
        ->assertSet('leadId', $lead->id)
        ->set('nextActionAt', '2026-10-05')
        ->call('save')
        ->assertHasNoErrors();

    expect($lead->fresh())->status->toBe('interested')->next_action_at->toDateString()->toBe('2026-10-05');
});

it('legt mit Taste 4 einen Termin an', function () {
    $lead = leadWithPriority('A');

    Livewire::test(CallList::class)
        ->call('selectKey', '4')
        ->set('appointment', ['date' => '2026-09-29', 'time' => '09:30', 'type' => 'onsite'])
        ->call('save')
        ->assertHasNoErrors();

    expect($lead->appointments()->first())->starts_at->format('Y-m-d H:i')->toBe('2026-09-29 09:30')->type->toBe('onsite');
});

it('fragt bei Taste 8 nach und speichert erst nach Bestätigung', function () {
    $lead = leadWithPriority('A');

    $component = Livewire::test(CallList::class)
        ->call('selectKey', '8')
        ->call('save')
        ->assertSet('confirmingObjection', true)
        ->assertSee('Werbewiderspruch speichern?');

    expect($lead->fresh()->status)->toBe('new')->and(BlocklistEntry::count())->toBe(0);

    $component->call('confirmObjection')->assertHasNoErrors();

    expect($lead->fresh()->status)->toBe('objection')->and(BlocklistEntry::count())->toBe(1);
});

it('warnt bei Einträgen auf der Sperrliste und lässt keinen Anruf zu', function () {
    $lead = leadWithPriority('A');
    BlocklistEntry::create(['company_name' => $lead->organization->name, 'postal_code' => $lead->organization->postal_code, 'reason' => 'Test']);

    Livewire::test(CallList::class)
        ->assertSet('leadId', $lead->id)
        ->assertSee('Kein Anruf möglich')
        ->assertDontSee('tel:'.$lead->organization->phone_e164)
        ->call('selectKey', '2')
        ->set('nextActionAt', '2026-10-05')
        ->call('save')
        ->assertHasErrors('status');

    expect($lead->fresh()->status)->toBe('new');
});

it('nimmt Privatpersonen ohne Einwilligung aus der Kaltakquise nicht in die Liste', function () {
    $cold = Lead::factory()->coldPrivate()->create();
    $consented = Lead::factory()->coldPrivate()->create(['contact_id' => Contact::factory()->private()->withPhoneConsent()]);
    $inbound = Lead::factory()->inboundPrivate('phone')->create();

    $ids = (new CallList)->queue()->pluck('leads.id')->all();

    expect($ids)->not->toContain($cold->id)
        ->toContain($consented->id)
        ->toContain($inbound->id);
});

it('fragt nach dem dritten Versuch, ob der Vorgang ruhen soll', function () {
    $lead = Lead::factory()->create(['call_attempts' => 2, 'organization_id' => Organization::factory()->state(['priority' => 'A'])]);

    Livewire::test(CallList::class)
        ->call('selectKey', '1')
        ->call('save')
        ->assertSet('restPromptLeadId', $lead->id)
        ->assertSee('Vorgang ruhen lassen?')
        ->call('rest', true);

    expect($lead->fresh()->next_action_at->toDateString())->toBe('2026-12-24');
});

it('setzt mit Taste 0 Cross-Selling, ohne den Status zu ändern', function () {
    $lead = leadWithPriority('A');

    Livewire::test(CallList::class)
        ->call('selectKey', '0')
        ->assertSet('crossSellingOpen', true)
        ->set('crossSellingDate', '2026-11-01')
        ->call('saveCrossSelling')
        ->assertHasNoErrors();

    expect($lead->fresh())->cross_selling->toBeTrue()->status->toBe('new');
});
