<?php

namespace App\Filament\Resources\CheckLevels;

use App\Filament\Resources\CheckLevels\Pages\CreateCheckLevel;
use App\Filament\Resources\CheckLevels\Pages\EditCheckLevel;
use App\Filament\Resources\CheckLevels\Pages\ListCheckLevels;
use App\Models\CheckLevel;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Prüfstufen der Leadliste: anlegen, umbenennen, sortieren, abschalten, löschen.
 * Nur Verwaltung. Änderungen stehen im Protokoll.
 */
class CheckLevelResource extends Resource
{
    protected static ?string $model = CheckLevel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 25;

    protected static ?string $modelLabel = 'Prüfstufe';

    protected static ?string $pluralModelLabel = 'Prüfstufen';

    protected static ?string $slug = 'pruefstufen';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return Gate::allows('settings.manage');
    }

    public static function canCreate(): bool
    {
        return Gate::allows('settings.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Gate::allows('settings.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return Gate::allows('settings.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Prüfstufe')
                ->schema([
                    TextInput::make('name')
                        ->label('Bezeichnung')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Erscheint im Formular der Organisation und als Spalte in der Importvorlage.'),
                    Textarea::make('description')
                        ->label('Was wird geprüft?')
                        ->rows(3)
                        ->helperText('Wird im Formular unter der Bezeichnung angezeigt.'),
                    Toggle::make('is_active')
                        ->label('aktiv')
                        ->default(true)
                        ->helperText('Inaktive Prüfstufen erscheinen nicht mehr im Formular und im Import. Vorhandene Ergebnisse bleiben erhalten.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn ($action, bool $isReordering) => $action->label($isReordering ? 'Reihenfolge fertig' : 'Reihenfolge ändern'))
            ->columns([
                TextColumn::make('sort_order')->label('Nr.')->alignCenter(),
                TextColumn::make('name')->label('Bezeichnung')->weight('medium')->searchable(),
                TextColumn::make('description')->label('Was wird geprüft?')->wrap()->placeholder('–'),
                ToggleColumn::make('is_active')->label('aktiv'),
                TextColumn::make('results_count')->label('Ergebnisse')->counts('results')->alignCenter(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(fn (CheckLevel $record) => 'Die Prüfstufe und ihre '.$record->results()->count().' Ergebnisse bei Organisationen werden gelöscht. Wenn Sie die Ergebnisse behalten möchten, schalten Sie die Prüfstufe stattdessen auf inaktiv.'),
            ])
            ->emptyStateHeading('Noch keine Prüfstufen');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCheckLevels::route('/'),
            'create' => CreateCheckLevel::route('/create'),
            'edit' => EditCheckLevel::route('/{record}/edit'),
        ];
    }
}
