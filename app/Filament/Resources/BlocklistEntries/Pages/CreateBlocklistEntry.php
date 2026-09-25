<?php

namespace App\Filament\Resources\BlocklistEntries\Pages;

use App\Filament\Resources\BlocklistEntries\BlocklistEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlocklistEntry extends CreateRecord
{
    protected static string $resource = BlocklistEntryResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
