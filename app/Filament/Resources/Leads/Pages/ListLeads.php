<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Support\Hilfe;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Vorgänge nach Phase: Akquise (Standard), Förderfall, Teilnehmer, geschlossen, alle.
 * Übergebene Vorgänge verschwinden so aus der Akquise, bleiben aber auffindbar.
 */
class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Vorgang anlegen'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'akquise' => Tab::make('Akquise')
                ->modifyQueryUsing(fn (Builder $query) => $query->inAcquisition())
                ->badge(fn () => Lead::inAcquisition()->count()),
            'foerderfall' => Tab::make('Förderfall')
                ->modifyQueryUsing(fn (Builder $query) => $query->open()->where('status', 'handed_over'))
                ->badge(fn () => Lead::open()->where('status', 'handed_over')->count())
                ->badgeColor('success'),
            'teilnehmer' => Tab::make('Teilnehmer')
                ->modifyQueryUsing(fn (Builder $query) => $query->open()->where('status', 'enrolled')),
            'geschlossen' => Tab::make('Geschlossen')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('closed_at')),
            'alle' => Tab::make('Alle'),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'akquise';
    }

    public function getSubheading(): ?string
    {
        return Hilfe::seite('vorgaenge');
    }
}
