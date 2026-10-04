<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Actions\LeadActions;
use App\Filament\Concerns\ListensForLeadRest;
use App\Filament\Resources\DuplicateCandidates\DuplicateCandidateResource;
use App\Filament\Resources\FundingCases\FundingCaseResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\DuplicateCandidate;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

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
            Action::make('duplicate')
                ->label('Dublettenverdacht prüfen')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('warning')
                ->visible(fn () => DuplicateCandidate::isLeadFlagged($this->getRecord()))
                ->tooltip('Dieser Eintrag ähnelt einem vorhandenen. Bis zur Entscheidung erscheint er nicht in der Anrufliste.')
                ->url(fn () => DuplicateCandidateResource::getUrl('index')),
            Action::make('fundingCase')
                ->label('Förderfall')
                ->icon(Heroicon::OutlinedAcademicCap)
                ->color('success')
                ->visible(fn () => $this->getRecord()->fundingCase()->exists())
                ->url(fn () => FundingCaseResource::getUrl('view', ['record' => $this->getRecord()->fundingCase()->value('id')])),
            LeadActions::sipgateCall(),
            LeadActions::setStatus(),
            LeadActions::crossSelling(),
            LeadActions::addActivity(),
            EditAction::make()->label('Bearbeiten')->color('gray'),
        ];
    }

    protected function getListeners(): array
    {
        return [...parent::getListeners(), 'refresh-lead' => '$refresh', 'documents-changed' => '$refresh'];
    }
}
