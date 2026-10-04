<?php

namespace App\Filament\Resources\DuplicateCandidates\Pages;

use App\Filament\Resources\DuplicateCandidates\DuplicateCandidateResource;
use App\Services\Duplicates\DuplicateFinder;
use App\Support\Hilfe;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListDuplicateCandidates extends ListRecords
{
    protected static string $resource = DuplicateCandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scan')
                ->label('Bestand prüfen')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Ganzen Bestand auf Dubletten prüfen?')
                ->modalDescription('Vergleicht alle Organisationen und Kontakte miteinander. Bereits entschiedene Paare werden nicht wieder gemeldet.')
                ->modalSubmitActionLabel('Prüfen')
                ->action(function () {
                    $count = app(DuplicateFinder::class)->scanAll();
                    Notification::make()->title($count === 1 ? '1 neuer Verdachtsfall' : "{$count} neue Verdachtsfälle")->success()->send();
                }),
        ];
    }

    public function getSubheading(): ?string
    {
        return Hilfe::seite('dubletten');
    }
}
