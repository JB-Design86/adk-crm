<?php

namespace App\Filament\Resources\FundingSteps\Pages;

use App\Filament\Resources\FundingSteps\FundingStepResource;
use Filament\Resources\Pages\EditRecord;

class EditFundingStep extends EditRecord
{
    protected static string $resource = FundingStepResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
