<?php

namespace App\Filament\Resources\FundingSteps\Pages;

use App\Filament\Resources\FundingSteps\FundingStepResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFundingStep extends CreateRecord
{
    protected static string $resource = FundingStepResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
