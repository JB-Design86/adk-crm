<?php

namespace App\Livewire;

use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Erinnerung an Rückrufe mit Uhrzeit, auf allen Seiten des CRM (Render-Hook im AdminPanelProvider).
 * Fragt beim Laden der Seite und danach jede Minute, solange im CRM gearbeitet wird. Gemeldet werden
 * eigene und nicht zugewiesene Vorgänge, jeder Rückruf (Vorgang, Datum, Uhrzeit) einmal je Anmeldung.
 */
class CallbackReminder extends Component
{
    private const SESSION_KEY = 'callback_reminders';

    public function check(): void
    {
        $user = auth()->user();

        if (! $user || Gate::denies('leads.view')) {
            return;
        }

        // Erst nur die Schlüssel, Firma und Kontakt nur für neue Erinnerungen laden.
        $due = Lead::query()
            ->open()
            ->callbackDue()
            ->where(fn (Builder $q) => $q->whereNull('assigned_to')->orWhere('assigned_to', $user->id))
            ->orderBy('next_action_at')
            ->orderBy('next_action_time')
            ->get(['id', 'organization_id', 'contact_id', 'next_action_at', 'next_action_time'])
            ->keyBy(fn (Lead $lead) => "{$lead->id}|{$lead->next_action_at->toDateString()}|{$lead->next_action_time}");

        $shown = session(self::SESSION_KEY, []);
        $new = $due->filter(fn (Lead $lead, string $key) => ! in_array($key, $shown, true));

        // Nur fällige merken: Erledigte fallen heraus, die Liste bleibt klein.
        session([self::SESSION_KEY => $due->keys()->all()]);

        $new->load(['organization', 'contact'])->each(fn (Lead $lead) => $this->notify($lead));
    }

    private function notify(Lead $lead): void
    {
        $when = $lead->next_action_at->isToday() ? "{$lead->next_action_time} Uhr" : $lead->nextActionLabel();

        Notification::make()
            ->title('Rückruf fällig: '.$lead->displayName())
            ->body(implode(' · ', array_filter([$when, $lead->phoneDisplay()])))
            ->warning()
            ->persistent()
            ->actions([
                Action::make('openLead')
                    ->label('Vorgang öffnen')
                    ->button()
                    ->url(LeadResource::getUrl('view', ['record' => $lead])),
            ])
            ->send();
    }

    public function render(): View
    {
        return view('livewire.callback-reminder');
    }
}
