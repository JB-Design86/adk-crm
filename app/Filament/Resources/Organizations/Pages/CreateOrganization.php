<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Resources\Organizations\OrganizationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrganization extends CreateRecord
{
    protected static string $resource = OrganizationResource::class;

    /** @var array<int|string, string> Prüfergebnisse aus dem Formular */
    protected array $checkStates = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->checkStates = $data['checks'] ?? [];
        unset($data['checks']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->getRecord()->syncChecks($this->checkStates);
    }
}
