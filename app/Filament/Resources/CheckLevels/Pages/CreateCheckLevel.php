<?php

namespace App\Filament\Resources\CheckLevels\Pages;

use App\Filament\Resources\CheckLevels\CheckLevelResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCheckLevel extends CreateRecord
{
    protected static string $resource = CheckLevelResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
