<?php

namespace App\Livewire;

use App\Filament\Documents\DocumentTable;
use App\Models\Lead;
use App\Models\Participant;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Dokumentenliste zum Einbetten (Förderfall, Teilnehmerakte).
 * Mit participant: Dokumente des Vorgangs und dieser Akte, hochgeladen wird an die Akte.
 */
class DocumentList extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    public Lead $lead;

    public ?Participant $participant = null;

    public function table(Table $table): Table
    {
        return DocumentTable::configure(
            // In der Akte: Dokumente des Vorgangs (Gutschein, Vertrag …) und dieser Akte, nicht die anderer Beschäftigter.
            $table->query(fn () => $this->lead->documents()->getQuery()
                ->when($this->participant, fn ($q) => $q->where(fn ($d) => $d->whereNull('participant_id')->orWhere('participant_id', $this->participant->id)))),
            fn () => $this->lead,
            fn () => $this->participant,
        );
    }

    public function render(): View
    {
        return view('livewire.document-list');
    }
}
