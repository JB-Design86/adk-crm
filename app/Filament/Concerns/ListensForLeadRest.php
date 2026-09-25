<?php

namespace App\Filament\Concerns;

use App\Models\Lead;
use App\Services\LeadStatusService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

/**
 * Antwort auf den Hinweis „Vorgang ruhen lassen?“ nach dem dritten Versuch.
 */
trait ListensForLeadRest
{
    #[On('rest-lead')]
    public function restLead(int $leadId): void
    {
        Gate::authorize('leads.edit');

        $lead = Lead::findOrFail($leadId);
        app(LeadStatusService::class)->rest($lead);

        Notification::make()
            ->title('Vorgang ruht bis '.$lead->next_action_at->format('d.m.Y'))
            ->success()
            ->send();
    }
}
