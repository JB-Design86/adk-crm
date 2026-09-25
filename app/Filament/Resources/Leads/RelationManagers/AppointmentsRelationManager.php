<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Models\Activity;
use App\Models\Appointment;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/**
 * Termine eines Vorgangs. Mitarbeitende bearbeiten eigene Termine, die Verwaltung alle.
 */
class AppointmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'appointments';

    protected static ?string $title = 'Termine';

    protected static ?string $modelLabel = 'Termin';

    protected static ?string $pluralModelLabel = 'Termine';

    public static function canManage(?Appointment $appointment = null): bool
    {
        if (Gate::allows('appointments.all')) {
            return true;
        }

        return Gate::allows('appointments.own') && ($appointment === null || $appointment->user_id === auth()->id());
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DateTimePicker::make('starts_at')->label('Datum und Uhrzeit')->seconds(false)->required(),
            Select::make('type')->label('Art')->options(config('adk.appointment_types'))->required(),
            Select::make('user_id')
                ->label('zuständig')
                ->options(fn () => User::where('is_blocked', false)->orderBy('name')->pluck('name', 'id'))
                ->default(fn () => auth()->id())
                ->disabled(fn () => ! Gate::allows('appointments.all'))
                ->dehydrated()
                ->required(),
            Textarea::make('notes')->label('Notiz')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('starts_at')->label('Termin')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('type')->label('Art')->formatStateUsing(fn (string $state) => config("adk.appointment_types.{$state}", $state)),
                TextColumn::make('user.name')->label('zuständig'),
                TextColumn::make('notes')->label('Notiz')->wrap()->placeholder('–'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Termin anlegen')
                    ->visible(fn () => static::canManage())
                    ->mutateDataUsing(function (array $data) {
                        if (! Gate::allows('appointments.all')) {
                            $data['user_id'] = auth()->id();
                        }

                        return $data;
                    })
                    ->after(fn (Appointment $record) => Activity::create([
                        'lead_id' => $record->lead_id,
                        'type' => 'appointment',
                        'body' => 'Termin angelegt: '.$record->starts_at->format('d.m.Y H:i').' ('.$record->typeLabel().')',
                    ])),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (Appointment $record) => static::canManage($record)),
                DeleteAction::make()->visible(fn (Appointment $record) => static::canManage($record)),
            ]);
    }
}
