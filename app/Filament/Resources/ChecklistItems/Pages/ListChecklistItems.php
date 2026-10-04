<?php

namespace App\Filament\Resources\ChecklistItems\Pages;

use App\Filament\Resources\ChecklistItems\ChecklistItemResource;
use App\Support\Hilfe;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChecklistItems extends ListRecords
{
    protected static string $resource = ChecklistItemResource::class;

    public function getSubheading(): ?string
    {
        return Hilfe::seite('checkliste');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Punkt hinzufügen'),
        ];
    }
}
