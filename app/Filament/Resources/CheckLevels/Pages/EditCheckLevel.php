<?php

namespace App\Filament\Resources\CheckLevels\Pages;

use App\Filament\Resources\CheckLevels\CheckLevelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCheckLevel extends EditRecord
{
    protected static string $resource = CheckLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
