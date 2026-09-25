<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Actions\LeadActions;
use App\Filament\Concerns\ListensForLeadRest;
use App\Filament\Resources\Leads\LeadResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Vorgang: alle Daten, Aktivitäten und Termine auf einer Seite.
 */
class ViewLead extends ViewRecord
{
    use ListensForLeadRest;

    protected static string $resource = LeadResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->displayName();
    }

    protected function getHeaderActions(): array
    {
        return [
            LeadActions::setStatus(),
            LeadActions::crossSelling(),
            LeadActions::addActivity(),
            EditAction::make()->label('Bearbeiten')->color('gray'),
        ];
    }

    protected function getListeners(): array
    {
        return [...parent::getListeners(), 'refresh-lead' => '$refresh'];
    }
}
