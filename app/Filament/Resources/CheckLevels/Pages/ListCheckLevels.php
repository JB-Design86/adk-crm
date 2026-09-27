<?php

namespace App\Filament\Resources\CheckLevels\Pages;

use App\Filament\Resources\CheckLevels\CheckLevelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCheckLevels extends ListRecords
{
    protected static string $resource = CheckLevelResource::class;

    public function getSubheading(): string
    {
        return 'Prüfstufen der Leadliste (Branchenmatrix). Reihenfolge per „Reihenfolge ändern“ und Ziehen anpassen.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Prüfstufe hinzufügen'),
        ];
    }
}
