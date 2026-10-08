<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Models\Activity;
use App\Support\Adk;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Aktivitäten eines Vorgangs. Nur lesen; erfasst wird über „Aktivität erfassen“ und „Status setzen“.
 */
class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Aktivitäten';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->activities()->count();
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('type')->label('Art')->badge()->color('gray')->formatStateUsing(fn (string $state) => Activity::TYPES[$state] ?? $state),
                TextColumn::make('status_change')
                    ->label('Status')
                    ->state(fn (Activity $record) => $record->status_to
                        ? trim(($record->status_from && $record->status_from !== $record->status_to ? Adk::statusLabel($record->status_from).' → ' : '').Adk::statusLabel($record->status_to))
                        : null)
                    ->placeholder('–'),
                // Zeilenumbrüche erhalten, z. B. bei E-Mails aus dem CRM (Text maskiert).
                TextColumn::make('body')->label('Text')->wrap()->placeholder('–')
                    ->formatStateUsing(fn (?string $state) => $state === null ? null : new HtmlString(nl2br(e($state), false))),
                TextColumn::make('user.name')->label('Benutzer')->placeholder('System'),
            ])
            ->paginated([10, 25, 50]);
    }
}
