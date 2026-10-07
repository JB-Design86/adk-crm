<?php

namespace App\Filament\Resources\FundingCases;

use App\Filament\Resources\FundingCases\Pages\ListFundingCases;
use App\Filament\Resources\FundingCases\Pages\ViewFundingCase;
use App\Models\FundingCase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Förderfälle (Stufe 3): feste Interessenten auf dem Weg zur Einschreibung.
 */
class FundingCaseResource extends Resource
{
    protected static ?string $model = FundingCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Akquise';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Förderfall';

    protected static ?string $pluralModelLabel = 'Förderfälle';

    protected static ?string $slug = 'foerderfaelle';

    public static function canViewAny(): bool
    {
        return Gate::allows('leads.view');
    }

    public static function canView(Model $record): bool
    {
        return Gate::allows('leads.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return Gate::allows('leads.edit');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record?->lead?->displayName() ?? 'Förderfall';
    }

    public static function getNavigationBadge(): ?string
    {
        $due = FundingCase::query()->open()
            ->whereHas('lead', fn (Builder $q) => $q->whereDate('next_action_at', '<=', today()))
            ->count();

        return $due ? (string) $due : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'heute fällig oder überfällig';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['lead.organization', 'lead.contact', 'lead.assignee', 'completedSteps'])
                ->leftJoin('leads', 'leads.id', '=', 'funding_cases.lead_id')
                ->select('funding_cases.*')
                ->orderByRaw('leads.next_action_at IS NULL')
                ->orderBy('leads.next_action_at'))
            ->columns([
                TextColumn::make('name')
                    ->label('Förderfall')
                    ->state(fn (FundingCase $record) => $record->lead->displayName())
                    ->description(fn (FundingCase $record) => $record->pathwayLabel())
                    ->weight('medium'),
                TextColumn::make('current_step')
                    ->label('Nächster Schritt')
                    ->state(fn (FundingCase $record) => $record->isOpen()
                        ? ($record->currentStep()?->name ?? 'Einschreibung bestätigen')
                        : $record->stateLabel())
                    ->description(fn (FundingCase $record) => $record->progressLabel().' erledigt')
                    ->wrap(),
                TextColumn::make('lead.next_action_at')
                    ->label('Wiedervorlage')
                    ->formatStateUsing(fn (FundingCase $record) => $record->lead->nextActionLabel())
                    ->color(fn (FundingCase $record) => $record->isOpen() && $record->lead->next_action_at?->lt(today()) ? 'danger' : null)
                    ->placeholder('–'),
                TextColumn::make('voucher_valid_until')
                    ->label('Gutschein gültig bis')
                    ->date('d.m.Y')
                    ->placeholder('–')
                    ->toggleable(),
                TextColumn::make('lead.assignee.name')->label('zuständig')->placeholder('–'),
                TextColumn::make('state')
                    ->label('Stand')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => FundingCase::STATES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'enrolled' => 'success',
                        'cancelled' => 'gray',
                        default => 'primary',
                    }),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Stand')
                    ->options(FundingCase::STATES)
                    ->default('open')
                    ->attribute('funding_cases.state'),
                SelectFilter::make('pathway')
                    ->label('Förderweg')
                    ->options(collect(config('adk.funding_pathways'))->map(fn (array $p) => $p['label'])->all())
                    ->attribute('funding_cases.pathway'),
                Filter::make('mine')
                    ->label('nur meine')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('leads.assigned_to', auth()->id())),
                Filter::make('due')
                    ->label('fällig oder überfällig')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereDate('leads.next_action_at', '<=', today())),
            ])
            ->recordUrl(fn (FundingCase $record) => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Keine Förderfälle')
            ->emptyStateDescription('Ein Förderfall entsteht, wenn bei einem Vorgang der Status „Übergeben an Förderweg“ gesetzt wird.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFundingCases::route('/'),
            'view' => ViewFundingCase::route('/{record}'),
        ];
    }
}
