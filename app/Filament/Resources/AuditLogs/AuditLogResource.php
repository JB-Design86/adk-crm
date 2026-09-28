<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
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
 * Protokoll: nur für die Verwaltung einsehbar, nicht änderbar.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'Protokolleintrag';

    protected static ?string $pluralModelLabel = 'Protokoll';

    protected static ?string $slug = 'protokoll';

    public const LOG_NAMES = [
        'auth' => 'Anmeldung',
        'leads' => 'Vorgang',
        'activities' => 'Aktivität',
        'appointments' => 'Termin',
        'organizations' => 'Organisation',
        'contacts' => 'Kontakt',
        'blocklist' => 'Sperrliste',
        'import' => 'Import',
        'export' => 'Export',
        'users' => 'Benutzer',
        'check_levels' => 'Prüfstufe',
        'organization_checks' => 'Prüfergebnis',
        'funding_cases' => 'Förderfall',
        'funding_steps' => 'Förderweg-Schritt',
        'retention' => 'Löschlauf',
    ];

    public const EVENTS = [
        'created' => 'angelegt',
        'updated' => 'geändert',
        'deleted' => 'gelöscht',
        'login' => 'Anmeldung',
        'logout' => 'Abmeldung',
        'failed' => 'Fehlversuch',
        'lockout' => 'Sperre nach Fehlversuchen',
        'import' => 'Import',
        'export' => 'Export',
        'retention' => 'Löschlauf',
        '2fa_reset' => '2FA zurückgesetzt',
    ];

    public static function canViewAny(): bool
    {
        return Gate::allows('audit.view');
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
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('causer'))
            ->columns([
                TextColumn::make('created_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('log_name')->label('Bereich')->badge()->color('gray')->formatStateUsing(fn (?string $state) => self::LOG_NAMES[$state] ?? $state),
                TextColumn::make('event')->label('Ereignis')->formatStateUsing(fn (?string $state) => self::EVENTS[$state] ?? $state),
                TextColumn::make('description')->label('Beschreibung')->wrap(),
                TextColumn::make('subject')->label('Datensatz')->state(fn (AuditLog $record) => $record->subject_type ? class_basename($record->subject_type).' '.$record->subject_id : null)->placeholder('–'),
                TextColumn::make('causer.name')->label('Benutzer')->placeholder('System'),
            ])
            ->filters([
                SelectFilter::make('log_name')->label('Bereich')->options(self::LOG_NAMES)->multiple(),
                SelectFilter::make('event')->label('Ereignis')->options(self::EVENTS)->multiple(),
                SelectFilter::make('causer_id')->label('Benutzer')->options(fn () => User::orderBy('name')->pluck('name', 'id')),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('von'),
                        DatePicker::make('until')->label('bis'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalHeading('Protokolleintrag')
                    ->schema([
                        TextEntry::make('created_at')->label('Zeitpunkt')->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('description')->label('Beschreibung'),
                        TextEntry::make('causer.name')->label('Benutzer')->placeholder('System'),
                        KeyValueEntry::make('old')->label('vorher')->state(fn (AuditLog $record) => self::flatten($record->attribute_changes?->get('old'))),
                        KeyValueEntry::make('attributes')->label('nachher')->state(fn (AuditLog $record) => self::flatten($record->attribute_changes?->get('attributes'))),
                        KeyValueEntry::make('properties')->label('Angaben')->state(fn (AuditLog $record) => self::flatten($record->properties?->all())),
                    ]),
            ]);
    }

    /** @return array<string, string> */
    private static function flatten(?array $values): array
    {
        return collect($values ?? [])
            ->map(fn ($value) => is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE))
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }
}
