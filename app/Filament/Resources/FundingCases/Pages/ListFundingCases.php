<?php

namespace App\Filament\Resources\FundingCases\Pages;

use App\Filament\Resources\FundingCases\FundingCaseResource;
use App\Support\Hilfe;
use Filament\Resources\Pages\ListRecords;

class ListFundingCases extends ListRecords
{
    protected static string $resource = FundingCaseResource::class;

    public function getSubheading(): ?string
    {
        return Hilfe::seite('foerderfaelle');
    }
}
