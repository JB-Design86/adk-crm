<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Services\Duplicates\DuplicateFinder;
use Filament\Resources\Pages\CreateRecord;

class CreateContact extends CreateRecord
{
    protected static string $resource = ContactResource::class;

    protected function afterCreate(): void
    {
        // Ähnliche Einträge: in die Dublettenprüfung.
        app(DuplicateFinder::class)->record($this->getRecord());
    }
}
