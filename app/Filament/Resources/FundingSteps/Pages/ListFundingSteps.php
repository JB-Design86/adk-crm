<?php

namespace App\Filament\Resources\FundingSteps\Pages;

use App\Filament\Resources\FundingSteps\FundingStepResource;
use App\Support\Hilfe;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFundingSteps extends ListRecords
{
    protected static string $resource = FundingStepResource::class;

    public function getSubheading(): ?string
    {
        return Hilfe::seite('foerderweg_schritte');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Schritt hinzufügen'),
        ];
    }
}
