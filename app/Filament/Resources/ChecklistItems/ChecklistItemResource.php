<?php

namespace App\Filament\Resources\ChecklistItems;

use App\Filament\Resources\ChecklistItems\Pages\CreateChecklistItem;
use App\Filament\Resources\ChecklistItems\Pages\EditChecklistItem;
use App\Filament\Resources\ChecklistItems\Pages\ListChecklistItems;
use App\Models\ParticipantChecklistItem;
use App\Support\Adk;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Checkliste der Teilnehmerakte (Lastenheft 7.1): Punkte je Phase, von der Verwaltung pflegbar.
 */
class ChecklistItemResource extends Resource
{
    protected static ?string $model = ParticipantChecklistItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 27;

    protected static ?string $modelLabel = 'Checklisten-Punkt';

    protected static ?string $pluralModelLabel = 'Checkliste Teilnehmer';

    protected static ?string $slug = 'checkliste-teilnehmer';

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

    /** Punkte mit Einträgen und vom CRM selbst abgehakte Punkte werden nicht gelöscht, sondern abgeschaltet. */
    public static function canDelete(Model $record): bool
    {
        return Gate::allows('settings.manage') && $record->key === null && ! $record->checks()->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Punkt')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('phase')->label('Phase')->options(config('adk.checklist_phases'))->required(),
                        TextInput::make('name')->label('Bezeichnung')->required()->maxLength(255),
                    ]),
                    Textarea::make('instructions')
                        ->label('Was ist zu tun?')
                        ->rows(4)
                        ->helperText('Erscheint beim Abhaken als Hinweis.'),
                    Grid::make(3)->schema([
                        Select::make('document_category')->label('Zugehöriges Dokument')->options(fn () => Adk::documentCategoryOptions(true)),
                        Toggle::make('repeatable')->label('mehrfach möglich')->helperText('z. B. Fehlzeitenmeldungen')->inline(false),
                        Toggle::make('is_active')->label('aktiv')->default(true)->inline(false),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('sort_order')->label('Nr.')->alignCenter(),
                TextColumn::make('phase')->label('Phase')->formatStateUsing(fn (string $state) => config("adk.checklist_phases.{$state}") ?? $state)->badge()->color('gray'),
                TextColumn::make('name')->label('Bezeichnung')->weight('medium')->wrap(),
                TextColumn::make('document_category')->label('Dokument')->formatStateUsing(fn (?string $state) => Adk::documentCategoryLabel($state))->placeholder('–'),
                TextColumn::make('repeatable')->label('mehrfach')->formatStateUsing(fn (bool $state) => $state ? 'ja' : '')->placeholder(''),
                ToggleColumn::make('is_active')->label('aktiv'),
            ])
            ->filters([
                SelectFilter::make('phase')->label('Phase')->options(config('adk.checklist_phases')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChecklistItems::route('/'),
            'create' => CreateChecklistItem::route('/create'),
            'edit' => EditChecklistItem::route('/{record}/edit'),
        ];
    }
}
