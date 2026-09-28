<?php

namespace App\Filament\Resources\CheckLevels\Pages;

use App\Filament\Resources\CheckLevels\CheckLevelResource;
use App\Support\Hilfe;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCheckLevels extends ListRecords
{
    protected static string $resource = CheckLevelResource::class;

    public function getSubheading(): string
    {
        return Hilfe::seite('pruefstufen').' Reihenfolge per „Reihenfolge ändern“ und Ziehen anpassen.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Prüfstufe hinzufügen'),
        ];
    }
}
