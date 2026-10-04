<?php

namespace App\Filament\Resources\ChecklistItems\Pages;

use App\Filament\Resources\ChecklistItems\ChecklistItemResource;
use Filament\Resources\Pages\EditRecord;

class EditChecklistItem extends EditRecord
{
    protected static string $resource = ChecklistItemResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
