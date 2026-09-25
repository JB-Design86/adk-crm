<?php

namespace App\Filament\Resources\Organizations\Schemas;

use App\Support\Adk;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrganizationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Organisation')->columns(3)->schema(self::fields()),
            Section::make('Prüfstufen (Branchenmatrix)')
                ->columns(5)
                ->collapsible()
                ->schema([
                    ...collect(config('adk.check_levels'))->map(fn (string $label, int $level) => ToggleButtons::make("check_{$level}_passed")
                        ->label($label)
                        ->boolean('bestanden', 'nicht bestanden')
                        ->grouped())->values()->all(),
                    Textarea::make('check_notes')->label('Bemerkung')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    /** Felder, auch für „neu anlegen“ direkt aus dem Vorgang. */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->label('Name')->required()->maxLength(255)->columnSpan(2),
            TextInput::make('legal_form')->label('Rechtsform')->maxLength(50),
            Select::make('industry')
                ->label('Branche')
                ->options(Adk::industryOptions())
                ->searchable()
                ->live()
                ->afterStateUpdated(fn ($state, callable $set) => $set('wz_code', config('adk.industries')[$state] ?? null)),
            TextInput::make('wz_code')->label('WZ-2008-Code')->maxLength(20),
            Select::make('priority')->label('Priorität')->options(Adk::priorityOptions()),
            TextInput::make('street')->label('Straße')->maxLength(255),
            TextInput::make('postal_code')->label('PLZ')->maxLength(10),
            TextInput::make('city')->label('Ort')->maxLength(255),
            TextInput::make('phone_display')->label('Telefon')->tel()->maxLength(40),
            TextInput::make('email')->label('E-Mail')->email()->maxLength(255),
            TextInput::make('website')->label('Website')->maxLength(255),
            TextInput::make('employee_count')->label('Mitarbeitende')->numeric()->minValue(0),
            ToggleButtons::make('is_training_company')->label('Ausbildungsbetrieb')->boolean()->grouped(),
            TextInput::make('source')->label('Quelle')->required()->maxLength(255)->helperText('Pflicht: woher stammen die Daten?'),
            DatePicker::make('retrieved_at')->label('Abrufdatum')->required()->maxDate(today())->default(today()),
        ];
    }
}
