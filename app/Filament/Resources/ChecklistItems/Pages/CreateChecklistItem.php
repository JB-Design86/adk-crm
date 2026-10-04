<?php

namespace App\Filament\Resources\ChecklistItems\Pages;

use App\Filament\Resources\ChecklistItems\ChecklistItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateChecklistItem extends CreateRecord
{
    protected static string $resource = ChecklistItemResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
