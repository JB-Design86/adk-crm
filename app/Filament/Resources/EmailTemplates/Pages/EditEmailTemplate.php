<?php

namespace App\Filament\Resources\EmailTemplates\Pages;

use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Support\MailHtml;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmailTemplate extends EditRecord
{
    protected static string $resource = EmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /** Text ohne Tags (ältere Vorlagen) als Absätze in den Editor, sonst gingen die Zeilenumbrüche verloren. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['body'] = MailHtml::normalize($data['body'] ?? null);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
