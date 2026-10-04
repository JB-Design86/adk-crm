<?php

namespace App\Filament\Resources\Participants;

use App\Filament\Resources\Participants\Pages\ListParticipants;
use App\Filament\Resources\Participants\Pages\ViewParticipant;
use App\Models\Participant;
use App\Support\Adk;
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
 * Teilnehmerakten (Stufe 4): eingeschriebene Teilnehmende mit Checkliste, Dokumenten und Verbleib.
 */
class ParticipantResource extends Resource
{
    protected static ?string $model = Participant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Teilnehmer';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Teilnehmerakte';

    protected static ?string $pluralModelLabel = 'Teilnehmerakten';

    protected static ?string $slug = 'teilnehmer';

    public static function canViewAny(): bool
    {
        return Gate::allows('participants');
    }

    public static function canView(Model $record): bool
    {
        return Gate::allows('participants');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return Gate::allows('participants');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record ? $record->displayName().' · '.$record->number : 'Teilnehmerakte';
    }

    public static function getNavigationBadge(): ?string
    {
        $due = Participant::whereDate('follow_up_on', '<=', today())->count();

        return $due ? (string) $due : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Verbleibserhebung fällig';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['contact.organization', 'fundingCase']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Teilnehmer/in')
                    ->state(fn (Participant $record) => $record->displayName())
                    ->description(fn (Participant $record) => $record->number.($record->contact?->organization ? ' · '.$record->contact->organization->name : ''))
                    ->weight('medium')
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                        ->where('number', 'like', "%{$search}%")
                        ->orWhereHas('contact', fn (Builder $c) => $c->where('last_name', 'like', "%{$search}%")->orWhere('first_name', 'like', "%{$search}%")))),
                TextColumn::make('course_name')->label('Kurs')->placeholder('–')->wrap(),
                TextColumn::make('course_starts_on')->label('Beginn')->date('d.m.Y')->placeholder('–')->sortable(),
                TextColumn::make('course_ends_on')->label('Ende')->date('d.m.Y')->placeholder('–')->sortable(),
                TextColumn::make('fundingCase.pathway')
                    ->label('Förderweg')
                    ->formatStateUsing(fn (?string $state) => config("adk.funding_pathways.{$state}.label", $state))
                    ->toggleable(),
                TextColumn::make('progress')
                    ->label('Checkliste')
                    ->state(function (Participant $record) {
                        ['done' => $done, 'total' => $total] = $record->progress();

                        return "{$done} von {$total}";
                    }),
                TextColumn::make('state')
                    ->label('Stand')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Adk::participantStateLabel($state))
                    ->color(fn (string $state) => Adk::participantStateColor($state)),
                TextColumn::make('follow_up_on')
                    ->label('Verbleib fällig')
                    ->date('d.m.Y')
                    ->placeholder('–')
                    ->color(fn (Participant $record) => $record->follow_up_on?->lte(today()) ? 'danger' : null)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('state')->label('Stand')->options(Adk::participantStateOptions())->multiple(),
                Filter::make('current')->label('nur laufende')->toggle()->default()->query(fn (Builder $query) => $query->current()),
                Filter::make('follow_up_due')->label('Verbleib fällig')->toggle()->query(fn (Builder $query) => $query->whereDate('follow_up_on', '<=', today())),
            ])
            ->recordUrl(fn (Participant $record) => static::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Keine Teilnehmerakten')
            ->emptyStateDescription('Eine Teilnehmerakte entsteht im Förderfall mit „Einschreibung bestätigt“, also erst nach offizieller Einschreibung und Bestätigung durch den Kostenträger.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListParticipants::route('/'),
            'view' => ViewParticipant::route('/{record}'),
        ];
    }
}
