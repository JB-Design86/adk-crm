<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    /** Ohne Zielgruppe (JB IT-Design blendet das Feld aus): Betrieb bzw. Person, Zuordnung offen. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['target_group'] ??= filled($data['organization_id'] ?? null) ? 'company_open' : 'open';

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
