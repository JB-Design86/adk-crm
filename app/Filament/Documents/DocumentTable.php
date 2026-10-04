<?php

namespace App\Filament\Documents;

use App\Models\Document;
use App\Models\Lead;
use App\Models\Participant;
use App\Services\Documents\DocumentService;
use App\Support\Adk;
use App\Support\Hilfe;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Dokumentenliste für Vorgang, Förderfall und Teilnehmerakte (gleiche Spalten und Aktionen).
 */
class DocumentTable
{
    /**
     * @param  Closure(): Lead  $lead
     * @param  Closure(): ?Participant  $participant  Akte, an der hochgeladen wird (sonst am Vorgang)
     */
    public static function configure(Table $table, Closure $lead, ?Closure $participant = null): Table
    {
        $participant ??= fn () => null;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => static::visible($query->with(['uploader', 'participant.contact'])))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Noch keine Dokumente')
            ->emptyStateDescription('Über „Dokument hochladen“ legen Sie z. B. Bildungsgutschein, Bewilligung oder unterschriebenen Vertrag ab. Die Dateien liegen verschlüsselt auf dem Server.')
            ->columns([
                TextColumn::make('title')
                    ->label('Dokument')
                    ->weight('medium')
                    ->description(fn (Document $record) => $record->original_name.' · '.$record->sizeLabel())
                    ->searchable(),
                TextColumn::make('category')
                    ->label('Art')
                    ->badge()
                    ->color(fn (Document $record) => $record->isHealth() ? 'danger' : 'gray')
                    ->formatStateUsing(fn (string $state) => Adk::documentCategoryLabel($state)),
                TextColumn::make('participant.number')
                    ->label('Akte')
                    ->placeholder('Vorgang')
                    ->toggleable(),
                TextColumn::make('document_date')->label('Datum')->date('d.m.Y')->placeholder('–')->sortable(),
                TextColumn::make('created_at')->label('hochgeladen')->dateTime('d.m.Y H:i')->sortable()
                    ->description(fn (Document $record) => $record->uploader?->name),
            ])
            ->filters([
                SelectFilter::make('category')->label('Art')->options(fn () => Adk::documentCategoryOptions(Gate::allows('health.view')))->multiple(),
            ])
            ->headerActions([static::uploadAction($lead, $participant)])
            ->recordActions([
                Action::make('open')
                    ->label('Ansehen')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->visible(fn (Document $record) => $record->isInlineViewable())
                    ->url(fn (Document $record) => route('filament.crm.documents.show', $record), shouldOpenInNewTab: true),
                Action::make('download')
                    ->label('Herunterladen')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->url(fn (Document $record) => route('filament.crm.documents.show', ['document' => $record, 'download' => 1])),
                Action::make('delete')
                    ->label('Löschen')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (Document $record) => $record->isDeletableBy(auth()->user()))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Document $record) => "Dokument „{$record->title}“ löschen?")
                    ->modalDescription('Die Datei wird endgültig gelöscht. Im Protokoll bleibt vermerkt, wer sie wann gelöscht hat.')
                    ->action(function (Document $record, $livewire) {
                        app(DocumentService::class)->delete($record);
                        $livewire->dispatch('documents-changed');
                        Notification::make()->title('Dokument gelöscht')->success()->send();
                    }),
            ])
            ->paginated([10, 25, 50]);
    }

    /** Gesundheitsangaben nur mit health.view, Dokumente der Teilnehmerakte nur mit participants. */
    public static function visible(Builder $query): Builder
    {
        $healthCategories = collect(config('adk.documents.categories'))->filter(fn (array $c) => $c['health'] ?? false)->keys()->all();

        return $query
            ->when(! Gate::allows('health.view'), fn (Builder $q) => $q->whereNotIn('category', $healthCategories))
            ->when(! Gate::allows('participants'), fn (Builder $q) => $q->whereNull('participant_id'));
    }

    /**
     * @param  Closure(): Lead  $lead
     * @param  Closure(): ?Participant  $participant
     */
    public static function uploadAction(Closure $lead, Closure $participant, ?string $category = null): Action
    {
        return Action::make('uploadDocument')
            ->label('Dokument hochladen')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn () => Gate::allows('documents') && ($participant() === null || Gate::allows('participants')))
            ->modalHeading('Dokument hochladen')
            ->modalDescription('Die Datei wird verschlüsselt gespeichert. Jeder Abruf steht im Protokoll.')
            ->modalSubmitActionLabel('Hochladen')
            ->schema(static::uploadSchema($category))
            ->action(function (array $data, Action $action, $livewire) use ($lead, $participant) {
                try {
                    app(DocumentService::class)->store($lead(), $data['file'], $data, $participant());
                } catch (ValidationException $exception) {
                    Notification::make()->title('Nicht hochgeladen')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();
                    $action->halt();

                    return;
                }

                // Seite drumherum (Schrittfolge, Pflichtunterlagen, Checkliste) neu zeichnen.
                $livewire->dispatch('documents-changed');
                Notification::make()->title('Dokument hochgeladen')->success()->send();
            });
    }

    /** @return list<Field> */
    public static function uploadSchema(?string $category = null, bool $required = true): array
    {
        return [
            FileUpload::make('file')
                ->label('Datei')
                ->storeFiles(false)
                ->required($required)
                ->maxSize(config('adk.documents.max_kb'))
                ->acceptedFileTypes(config('adk.documents.mime_types'))
                ->helperText('PDF, JPG, PNG, Word, Excel, ODT oder E-Mail, höchstens '.(int) (config('adk.documents.max_kb') / 1024).' MB.'),
            Select::make('category')
                ->label('Art')
                ->options(fn () => Adk::documentCategoryOptions(Gate::allows('health.view')))
                ->default($category)
                ->required($required)
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, tooltip: Hilfe::feld('document_category')),
            TextInput::make('title')->label('Bezeichnung')->maxLength(200)->placeholder('leer: Dateiname'),
            DatePicker::make('document_date')->label('Datum des Dokuments')->maxDate(today()),
            Textarea::make('notes')->label('Notiz')->rows(2),
        ];
    }
}
