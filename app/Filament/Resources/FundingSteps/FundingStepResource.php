<?php

namespace App\Filament\Resources\FundingSteps;

use App\Filament\Resources\FundingSteps\Pages\CreateFundingStep;
use App\Filament\Resources\FundingSteps\Pages\EditFundingStep;
use App\Filament\Resources\FundingSteps\Pages\ListFundingSteps;
use App\Models\FundingStep;
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
 * Schritte der Förderwege mit Erklärtext. Nur Verwaltung, Änderungen im Protokoll.
 */
class FundingStepResource extends Resource
{
    protected static ?string $model = FundingStep::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 26;

    protected static ?string $modelLabel = 'Förderweg-Schritt';

    protected static ?string $pluralModelLabel = 'Förderweg-Schritte';

    protected static ?string $slug = 'foerderweg-schritte';

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

    /** Schritte mit Einträgen werden nicht gelöscht, sondern abgeschaltet. */
    public static function canDelete(Model $record): bool
    {
        return Gate::allows('settings.manage') && ! $record->completions()->exists();
    }

    public static function pathwayOptions(): array
    {
        // Förderwege ohne angebotene Zielgruppe (z. B. Arbeitsunfall) ausblenden.
        return collect(config('adk.funding_pathways'))
            ->filter(fn (array $pathway) => collect($pathway['target_groups'])->contains(fn (string $group) => Adk::isActiveTargetGroup($group)))
            ->map(fn (array $pathway) => $pathway['label'])
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Schritt')
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('pathway')->label('Förderweg')->options(self::pathwayOptions())->required(),
                        TextInput::make('name')->label('Bezeichnung')->required()->maxLength(255),
                    ]),
                    Textarea::make('instructions')
                        ->label('Was ist jetzt von unserer Seite zu tun?')
                        ->rows(6)
                        ->helperText('Erklärtext, der beim Förderfall groß angezeigt wird, solange dieser Schritt ansteht. Konkrete Handgriffe, Vorlagen, Hinweise.'),
                    Grid::make(4)->schema([
                        Select::make('default_party')->label('Wer handelt meist?')->options(config('adk.funding_parties')),
                        TextInput::make('follow_up_days')->label('Wiedervorlage nach')->numeric()->minValue(0)->maxValue(365)->suffix('Tagen')->required(),
                        Toggle::make('calendar_days')->label('Kalendertage statt Arbeitstage')->inline(false),
                        Toggle::make('can_fail')->label('Kann abgelehnt werden')->inline(false),
                    ]),
                    Grid::make(2)->schema([
                        Select::make('document_category')
                            ->label('Erwartete Unterlage')
                            ->options(fn () => Adk::documentCategoryOptions())
                            ->helperText('Wird beim Erledigen zum Hochladen vorgeschlagen.'),
                        Toggle::make('requires_document')
                            ->label('Pflicht vor der Einschreibung')
                            ->helperText('„Einschreibung bestätigt“ geht erst, wenn diese Unterlage im Vorgang liegt.')
                            ->inline(false),
                    ]),
                    Toggle::make('is_active')->label('aktiv')->default(true),
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
                TextColumn::make('pathway')->label('Förderweg')->formatStateUsing(fn (string $state) => self::pathwayOptions()[$state] ?? $state)->badge()->color('gray'),
                TextColumn::make('name')->label('Bezeichnung')->weight('medium')->wrap(),
                TextColumn::make('instructions')->label('Was ist zu tun?')->limit(80)->wrap()->placeholder('–'),
                TextColumn::make('document_category')->label('Unterlage')->formatStateUsing(fn (?string $state, FundingStep $record) => Adk::documentCategoryLabel($state).($record->requires_document ? ' (Pflicht)' : ''))->placeholder('–')->toggleable(),
                TextColumn::make('follow_up_days')->label('Wiedervorlage')->formatStateUsing(fn (FundingStep $record) => $record->followUpLabel()),
                ToggleColumn::make('is_active')->label('aktiv'),
            ])
            ->filters([
                SelectFilter::make('pathway')->label('Förderweg')->options(self::pathwayOptions())->default('voucher'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFundingSteps::route('/'),
            'create' => CreateFundingStep::route('/create'),
            'edit' => EditFundingStep::route('/{record}/edit'),
        ];
    }
}
