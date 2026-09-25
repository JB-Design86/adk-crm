<?php

namespace App\Filament\Resources\BlocklistEntries;

use App\Filament\Resources\BlocklistEntries\Pages\CreateBlocklistEntry;
use App\Filament\Resources\BlocklistEntries\Pages\ListBlocklistEntries;
use App\Models\BlocklistEntry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
 * Sperrliste: nur Verwaltung. Einsehen, von Hand ergänzen,
 * löschen nur mit Begründung (protokolliert). Keine Bearbeitung.
 */
class BlocklistEntryResource extends Resource
{
    protected static ?string $model = BlocklistEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Sperrlisteneintrag';

    protected static ?string $pluralModelLabel = 'Sperrliste';

    protected static ?string $slug = 'sperrliste';

    public static function canViewAny(): bool
    {
        return Gate::allows('blocklist.manage');
    }

    public static function canCreate(): bool
    {
        return Gate::allows('blocklist.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return Gate::allows('blocklist.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Eintrag')
                ->description('Mindestens Telefon, E-Mail oder Firmenname mit PLZ angeben. Einträge gelten unbefristet.')
                ->columns(2)
                ->schema([
                    TextInput::make('phone_e164')
                        ->label('Telefon')
                        ->tel()
                        ->helperText('Wird automatisch in die Form +49… umgewandelt.')
                        ->requiredWithoutAll('email,company_name'),
                    TextInput::make('email')
                        ->label('E-Mail')
                        ->email()
                        ->requiredWithoutAll('phone_e164,company_name'),
                    TextInput::make('company_name')
                        ->label('Firmenname')
                        ->requiredWithoutAll('phone_e164,email'),
                    TextInput::make('postal_code')
                        ->label('PLZ')
                        ->requiredWith('company_name'),
                    DatePicker::make('blocked_on')
                        ->label('Datum')
                        ->default(today())
                        ->required(),
                    TextInput::make('reason')
                        ->label('Anlass')
                        ->default('Werbewiderspruch')
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('blocked_on', 'desc')
            ->columns([
                TextColumn::make('phone_e164')->label('Telefon')->searchable(),
                TextColumn::make('email')->label('E-Mail')->searchable(),
                TextColumn::make('company_name')->label('Firma')->searchable(),
                TextColumn::make('postal_code')->label('PLZ')->searchable(),
                TextColumn::make('blocked_on')->label('Datum')->date('d.m.Y')->sortable(),
                TextColumn::make('reason')->label('Anlass')->wrap(),
                TextColumn::make('creator.name')->label('eingetragen von'),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label('Löschen')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (BlocklistEntry $record) => static::canDelete($record))
                    ->modalHeading('Sperrlisteneintrag löschen')
                    ->modalDescription('Das Löschen wird mit Begründung protokolliert. Danach darf die Person oder Firma wieder angesprochen werden.')
                    ->schema([
                        Textarea::make('justification')
                            ->label('Begründung')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function (BlocklistEntry $record, array $data) {
                        static::deleteWithJustification($record, $data['justification']);

                        Notification::make()->title('Eintrag gelöscht')->success()->send();
                    }),
            ]);
    }

    public static function deleteWithJustification(BlocklistEntry $entry, string $justification): void
    {
        Gate::authorize('blocklist.manage');

        activity('blocklist')
            ->performedOn($entry)
            ->event('deleted')
            ->withProperties([
                'justification' => $justification,
                'entry' => $entry->only(['phone_e164', 'email', 'company_name', 'postal_code', 'blocked_on', 'reason']),
            ])
            ->log('Sperrlisteneintrag gelöscht');

        $entry->disableLogging();
        $entry->delete();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBlocklistEntries::route('/'),
            'create' => CreateBlocklistEntry::route('/create'),
        ];
    }
}
