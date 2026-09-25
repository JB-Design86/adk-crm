<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ImportLogs\ImportLogResource;
use App\Services\ImportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Import der Leadliste: Datei hochladen, Quelle und Abrufdatum angeben,
 * Spalten zuordnen, Vorschau prüfen, einspielen.
 */
class ImportLeads extends Page
{
    protected string $view = 'filament.pages.import-leads';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Import';

    protected static ?string $title = 'Leadliste importieren';

    protected static ?string $slug = 'import';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** @var array<string, mixed> */
    public ?array $mapping = [];

    public string $step = 'upload';

    public ?string $storedPath = null;

    public ?string $originalName = null;

    /** @var array{header: list<string>, rows: list<list<string>>, total: int}|null */
    public ?array $preview = null;

    public static function canAccess(): bool
    {
        return Gate::allows('import');
    }

    public function mount(): void
    {
        $this->form->fill(['retrieved_at' => null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('1. Datei und Herkunft')
                    ->description('Quelle und Abrufdatum sind Pflicht. Ohne sie wird nichts eingespielt.')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('file')
                            ->label('Datei (CSV oder XLSX)')
                            ->disk('local')
                            ->directory('imports')
                            ->visibility('private')
                            ->storeFileNamesIn('original_name')
                            ->acceptedFileTypes([
                                'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->maxSize(20480)
                            ->required()
                            ->columnSpanFull(),
                        TextInput::make('source')
                            ->label('Quelle')
                            ->placeholder('z. B. Leadliste Rhein-Main, Branchenbuch …')
                            ->required()
                            ->maxLength(255),
                        DatePicker::make('retrieved_at')
                            ->label('Abrufdatum')
                            ->maxDate(today())
                            ->required(),
                    ]),
            ]);
    }

    public function mappingForm(Schema $schema): Schema
    {
        $options = collect($this->preview['header'] ?? [])
            ->mapWithKeys(fn (string $title, int $index) => [$index => $title !== '' ? $title : 'Spalte '.($index + 1)])
            ->all();

        return $schema
            ->statePath('mapping')
            ->components([
                Section::make('2. Spalten zuordnen')
                    ->description('Links das Feld im CRM, rechts die Spalte aus Ihrer Datei. Nicht zugeordnete Felder bleiben leer.')
                    ->columns(3)
                    ->schema(collect(ImportService::FIELDS)
                        ->map(fn (array $field, string $key) => Select::make($key)
                            ->label($field['label'])
                            ->options($options)
                            ->placeholder('– nicht importieren –')
                            ->live()
                            ->required($key === 'name'))
                        ->values()
                        ->all()),
            ]);
    }

    public function analyze(ImportService $service): void
    {
        $state = $this->form->getState();

        $this->storedPath = $state['file'];
        $this->originalName = $state['original_name'] ?? basename($state['file']);

        try {
            $this->preview = $service->preview($this->absolutePath(), $this->extension());
        } catch (ValidationException $exception) {
            $this->discardFile();
            throw $exception;
        } catch (\Throwable $exception) {
            $this->discardFile();
            Notification::make()->title('Die Datei konnte nicht gelesen werden.')->body($exception->getMessage())->danger()->send();

            return;
        }

        $this->mappingForm->fill($service->guessMapping($this->preview['header']));
        $this->step = 'mapping';
    }

    public function runImport(ImportService $service): void
    {
        Gate::authorize('import');

        $mapping = $this->mappingForm->getState();

        $log = $service->import(
            path: $this->absolutePath(),
            extension: $this->extension(),
            fileName: $this->originalName,
            source: $this->data['source'] ?? null,
            retrievedAt: $this->data['retrieved_at'] ?? null,
            mapping: $mapping,
            user: auth()->user(),
        );

        $this->discardFile();

        Notification::make()
            ->title('Import abgeschlossen')
            ->body("{$log->rows_imported} von {$log->rows_total} Zeilen eingespielt, {$log->rows_skipped} übersprungen (davon {$log->duplicates} Dubletten).")
            ->success()
            ->persistent()
            ->send();

        $this->redirect(ImportLogResource::getUrl('view', ['record' => $log]));
    }

    public function restart(): void
    {
        $this->discardFile();
        $this->reset(['step', 'preview', 'mapping', 'storedPath', 'originalName']);
        $this->form->fill();
    }

    /** Vorschau der ersten Zeilen mit der aktuellen Zuordnung. */
    public function mappedPreview(): array
    {
        $rows = [];

        foreach ($this->preview['rows'] ?? [] as $row) {
            $mapped = [];

            foreach (ImportService::FIELDS as $key => $field) {
                $index = $this->mapping[$key] ?? null;

                if ($index !== null && $index !== '') {
                    $mapped[$field['label']] = $row[(int) $index] ?? '';
                }
            }

            $rows[] = $mapped;
        }

        return $rows;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('template')
                ->label('Mustervorlage herunterladen')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->action(fn () => response()->download(base_path('docs/import_vorlage.xlsx'), 'import_vorlage.xlsx')),
        ];
    }

    private function absolutePath(): string
    {
        return Storage::disk('local')->path($this->storedPath);
    }

    private function extension(): string
    {
        return strtolower(pathinfo($this->originalName ?: $this->storedPath, PATHINFO_EXTENSION));
    }

    private function discardFile(): void
    {
        if ($this->storedPath) {
            Storage::disk('local')->delete($this->storedPath);
        }
    }
}
