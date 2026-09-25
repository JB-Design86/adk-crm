<?php

namespace App\Filament\Resources\ImportLogs;

use App\Filament\Resources\ImportLogs\Pages\ListImportLogs;
use App\Filament\Resources\ImportLogs\Pages\ViewImportLog;
use App\Models\ImportLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Importprotokoll. Nur lesen, nicht änderbar.
 */
class ImportLogResource extends Resource
{
    protected static ?string $model = ImportLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 11;

    protected static ?string $modelLabel = 'Import';

    protected static ?string $pluralModelLabel = 'Importe';

    protected static ?string $slug = 'importe';

    public static function canViewAny(): bool
    {
        return Gate::allows('import');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('file_name')->label('Datei')->searchable(),
                TextColumn::make('source')->label('Quelle')->searchable(),
                TextColumn::make('retrieved_at')->label('Abrufdatum')->date('d.m.Y'),
                TextColumn::make('rows_total')->label('Zeilen')->numeric(),
                TextColumn::make('rows_imported')->label('importiert')->numeric(),
                TextColumn::make('rows_skipped')->label('übersprungen')->numeric(),
                TextColumn::make('duplicates')->label('Dubletten')->numeric(),
                TextColumn::make('user.name')->label('ausgeführt von'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Import')
                ->columns(3)
                ->schema([
                    TextEntry::make('file_name')->label('Datei'),
                    TextEntry::make('source')->label('Quelle'),
                    TextEntry::make('retrieved_at')->label('Abrufdatum')->date('d.m.Y'),
                    TextEntry::make('user.name')->label('ausgeführt von'),
                    TextEntry::make('created_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i'),
                    TextEntry::make('rows_total')->label('Zeilen gesamt'),
                    TextEntry::make('rows_imported')->label('importiert'),
                    TextEntry::make('rows_skipped')->label('übersprungen'),
                    TextEntry::make('duplicates')->label('davon Dubletten'),
                ]),
            Section::make('Übersprungene Zeilen')
                ->schema([
                    RepeatableEntry::make('skipped_rows')
                        ->hiddenLabel()
                        ->placeholder('Keine Zeile übersprungen.')
                        ->table([
                            RepeatableEntry\TableColumn::make('Zeile'),
                            RepeatableEntry\TableColumn::make('Firmenname'),
                            RepeatableEntry\TableColumn::make('Grund'),
                        ])
                        ->schema([
                            TextEntry::make('row'),
                            TextEntry::make('name'),
                            TextEntry::make('reason'),
                        ]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportLogs::route('/'),
            'view' => ViewImportLog::route('/{record}'),
        ];
    }
}
