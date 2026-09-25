<?php

namespace App\Filament\Resources\BlocklistEntries\Pages;

use App\Filament\Resources\BlocklistEntries\BlocklistEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlocklistEntries extends ListRecords
{
    protected static string $resource = BlocklistEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Eintrag hinzufügen'),
        ];
    }
}
