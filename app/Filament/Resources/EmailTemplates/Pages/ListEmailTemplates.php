<?php

namespace App\Filament\Resources\EmailTemplates\Pages;

use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Support\Hilfe;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmailTemplates extends ListRecords
{
    protected static string $resource = EmailTemplateResource::class;

    public function getSubheading(): ?string
    {
        return Hilfe::seite('e_mail_vorlagen');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Vorlage hinzufügen'),
        ];
    }
}
