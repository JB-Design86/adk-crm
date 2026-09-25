<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Support\Adk;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/**
 * Benutzerverwaltung: nur Verwaltung. Konten werden gesperrt, nicht gelöscht.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Benutzer';

    protected static ?string $pluralModelLabel = 'Benutzer';

    protected static ?string $slug = 'benutzer';

    public static function canViewAny(): bool
    {
        return Gate::allows('users.manage');
    }

    public static function canCreate(): bool
    {
        return Gate::allows('users.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Gate::allows('users.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Konto')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Name')->required()->maxLength(255),
                    TextInput::make('email')->label('E-Mail (Anmeldename)')->email()->required()->unique(ignoreRecord: true),
                    Select::make('role')->label('Rolle')->options(Adk::roleOptions())->required()->default('staff'),
                    TextInput::make('password')
                        ->label(fn ($livewire) => $livewire instanceof CreateRecord ? 'Kennwort' : 'Neues Kennwort')
                        ->password()
                        ->revealable()
                        ->rule(Password::min(10))
                        ->required(fn ($livewire) => $livewire instanceof CreateRecord)
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->confirmed()
                        ->helperText('Mindestens 10 Zeichen. Die Zwei-Faktor-Anmeldung richtet die Person bei der ersten Anmeldung ein.'),
                    TextInput::make('password_confirmation')
                        ->label('Kennwort wiederholen')
                        ->password()
                        ->required(fn ($livewire) => $livewire instanceof CreateRecord)
                        ->dehydrated(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('email')->label('E-Mail')->searchable(),
                TextColumn::make('role')->label('Rolle')->badge()->formatStateUsing(fn (string $state) => Adk::roleOptions()[$state] ?? $state),
                IconColumn::make('two_factor')->label('2FA')->boolean()->state(fn (User $record) => $record->hasTwoFactor()),
                IconColumn::make('is_blocked')->label('gesperrt')->boolean()->trueColor('danger')->falseColor('gray'),
                TextColumn::make('last_login_at')->label('letzte Anmeldung')->dateTime('d.m.Y H:i')->placeholder('noch nie'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('block')
                    ->label(fn (User $record) => $record->is_blocked ? 'Entsperren' : 'Sperren')
                    ->icon(fn (User $record) => $record->is_blocked ? Heroicon::OutlinedLockOpen : Heroicon::OutlinedLockClosed)
                    ->color(fn (User $record) => $record->is_blocked ? 'gray' : 'danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (User $record) => $record->is_blocked
                        ? 'Die Person kann sich danach wieder anmelden.'
                        : 'Die Person wird sofort abgemeldet und kann sich nicht mehr anmelden. Es gehen keine Daten verloren.')
                    ->hidden(fn (User $record) => $record->is($user = auth()->user()))
                    ->action(function (User $record) {
                        $record->is_blocked ? $record->unblock() : $record->block();
                        Notification::make()->title($record->is_blocked ? 'Konto gesperrt' : 'Konto entsperrt')->success()->send();
                    }),
                Action::make('resetTwoFactor')
                    ->label('2FA zurücksetzen')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Zum Beispiel bei Verlust des Telefons. Die Person muss die Zwei-Faktor-Anmeldung bei der nächsten Anmeldung neu einrichten.')
                    ->visible(fn (User $record) => $record->hasTwoFactor())
                    ->action(function (User $record) {
                        $record->saveAppAuthenticationSecret(null);
                        $record->saveAppAuthenticationRecoveryCodes(null);

                        activity('users')->performedOn($record)->event('2fa_reset')->log('Zwei-Faktor-Anmeldung zurückgesetzt');

                        Notification::make()->title('Zwei-Faktor-Anmeldung zurückgesetzt')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
