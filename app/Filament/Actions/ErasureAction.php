<?php

namespace App\Filament\Actions;

use App\Models\Contact;
use App\Models\Organization;
use App\Services\ErasureService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Löschersuchen (Art. 17 DSGVO) für eine Organisation oder einen Kontakt. Nur Verwaltung.
 */
class ErasureAction
{
    /**
     * @param  Closure(): (Organization|Contact|null)  $subject
     * @param  Closure(): string  $redirect  wohin nach dem Löschen
     */
    public static function make(Closure $subject, Closure $redirect, string $name = 'erase'): Action
    {
        return Action::make($name)
            ->label('Löschersuchen (Art. 17)')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn () => Gate::allows('erasure') && $subject() !== null)
            ->modalHeading(fn () => 'Endgültig löschen: '.self::name($subject()))
            ->modalDescription(fn () => self::description($subject()))
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalIconColor('danger')
            ->schema(fn () => app(ErasureService::class)->preview($subject())['blockers'] ? [] : [
                TextInput::make('reason')
                    ->label('Anlass')
                    ->placeholder('z. B. E-Mail vom 04.10.2026')
                    ->helperText('Steht im Protokoll. Bitte ohne Namen.')
                    ->required()
                    ->maxLength(200),
                Toggle::make('blocklist')
                    ->label('Auf die Sperrliste setzen (empfohlen)')
                    ->helperText('Telefon, E-Mail bzw. Firmenname mit PLZ bleiben gesperrt, damit ein späterer Import die Person nicht wieder anlegt und niemand erneut anruft.')
                    ->default(true),
                Checkbox::make('confirmed')
                    ->label('Ich habe geprüft, dass keine Aufbewahrungspflicht entgegensteht. Die Löschung lässt sich nicht rückgängig machen.')
                    ->accepted()
                    ->required(),
            ])
            ->modalSubmitActionLabel('Endgültig löschen')
            ->modalSubmitAction(fn (Action $action) => $action->disabled((bool) app(ErasureService::class)->preview($subject())['blockers']))
            ->action(function (array $data, Action $action) use ($subject, $redirect) {
                try {
                    $counts = app(ErasureService::class)->erase($subject(), $data['reason'] ?? '', (bool) ($data['blocklist'] ?? false));
                } catch (ValidationException $exception) {
                    Notification::make()->title('Nicht gelöscht')->body(implode(' ', $exception->validator->errors()->all()))->danger()->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title('Gelöscht')
                    ->body("{$counts['leads']} Vorgänge, {$counts['contacts']} Kontakte, {$counts['documents']} Dokumente.".(($data['blocklist'] ?? false) ? ' Auf der Sperrliste.' : ''))
                    ->success()
                    ->send();

                return redirect($redirect());
            });
    }

    private static function name(Organization|Contact|null $subject): string
    {
        return match (true) {
            $subject instanceof Organization => $subject->name,
            $subject instanceof Contact => $subject->fullName(),
            default => '',
        };
    }

    private static function description(Organization|Contact|null $subject): string
    {
        if (! $subject) {
            return '';
        }

        $preview = app(ErasureService::class)->preview($subject);

        if ($preview['blockers']) {
            return 'Löschen nicht möglich. '.implode(' ', $preview['blockers']);
        }

        $text = "Gelöscht werden sofort und endgültig: {$preview['leads']} Vorgänge mit {$preview['activities']} Aktivitäten, {$preview['contacts']} Kontakte und {$preview['documents']} Dokumente, dazu die Einträge im Protokoll.";

        if ($preview['keeps_leads']) {
            $text .= " Bei {$preview['keeps_leads']} Vorgängen eines Betriebs wird nur die Ansprechperson entfernt, der Vorgang bleibt. Notizen dort bitte selbst auf den Namen prüfen.";
        }

        return $text;
    }
}
