<?php

namespace App\Filament\Resources\Organizations\Schemas;

use App\Models\CheckLevel;
use App\Support\Adk;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class OrganizationForm
{
    public const CHECK_STATES = [
        'passed' => 'bestanden',
        'failed' => 'nicht bestanden',
        'open' => 'offen',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Organisation')->columns(3)->columnSpanFull()->schema(self::fields()),
            Section::make('Prüfstufen (Branchenmatrix)')
                ->description('Die Prüfstufen pflegt die Verwaltung unter Verwaltung → Prüfstufen.')
                ->columnSpanFull()
                ->collapsible()
                ->schema([
                    ...self::checkFields(),
                    Textarea::make('check_notes')->label('Bemerkung')->rows(2),
                ]),
        ]);
    }

    /**
     * Eine Zeile je aktiver Prüfstufe. Der Zustand liegt unter checks.{id}
     * und wird von den Seiten Create/EditOrganization gespeichert.
     *
     * @return list<ToggleButtons>
     */
    public static function checkFields(): array
    {
        return CheckLevel::activeOrdered()
            ->map(fn (CheckLevel $level) => ToggleButtons::make("checks.{$level->id}")
                ->label($level->name)
                ->helperText($level->description)
                ->options(self::CHECK_STATES)
                ->colors(['passed' => 'success', 'failed' => 'danger', 'open' => 'gray'])
                ->icons(['passed' => Heroicon::OutlinedCheck, 'failed' => Heroicon::OutlinedXMark, 'open' => Heroicon::OutlinedMinus])
                ->default('open')
                ->inline())
            ->all();
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
            ToggleButtons::make('is_training_company')->label('Ausbildungsbetrieb')->boolean()->inline(),
            TextInput::make('source')->label('Quelle')->required()->maxLength(255)->helperText('Pflicht: woher stammen die Daten?'),
            DatePicker::make('retrieved_at')->label('Abrufdatum')->required()->maxDate(today())->default(today()),
        ];
    }
}
