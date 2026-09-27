<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use Filament\Resources\Pages\EditRecord;

class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    /** @var array<int|string, string> Prüfergebnisse aus dem Formular */
    protected array $checkStates = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Organization $record */
        $record = $this->getRecord();
        $data['checks'] = $record->checkStates();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->checkStates = $data['checks'] ?? [];
        unset($data['checks']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->getRecord()->syncChecks($this->checkStates);
    }
}
