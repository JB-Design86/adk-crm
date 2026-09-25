<?php

namespace App\Filament\Resources\Contacts\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContactForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Person')->columns(3)->schema([
                ...self::fields(),
                Select::make('organization_id')
                    ->label('Organisation')
                    ->relationship('organization', 'name')
                    ->searchable(),
            ]),
            Section::make('Datenschutz und Einwilligungen')
                ->columns(2)
                ->schema(self::consentFields()),
        ]);
    }

    /** Grunddaten, auch für „neu anlegen“ direkt aus dem Vorgang. */
    public static function fields(): array
    {
        return [
            Select::make('salutation')->label('Anrede')->options(['Frau' => 'Frau', 'Herr' => 'Herr', 'divers' => 'divers']),
            TextInput::make('first_name')->label('Vorname')->maxLength(255),
            TextInput::make('last_name')->label('Nachname')->required()->maxLength(255),
            TextInput::make('position')->label('Funktion')->maxLength(255),
            TextInput::make('phone_display')->label('Telefon')->tel()->maxLength(40),
            TextInput::make('email')->label('E-Mail')->email()->maxLength(255),
            Toggle::make('is_private')->label('Privatperson')->live()->inline(false),
        ];
    }

    public static function consentFields(): array
    {
        return [
            DatePicker::make('privacy_notice_sent_at')->label('Datenschutzhinweis übermittelt am')->maxDate(today()),
            DatePicker::make('phone_consent_at')
                ->label('Einwilligung Telefonansprache am')
                ->maxDate(today())
                ->helperText('Pflicht für Anrufe bei Privatpersonen aus der Kaltakquise.')
                ->requiredWith('phone_consent_proof'),
            Textarea::make('phone_consent_proof')
                ->label('Nachweis Einwilligung Telefonansprache')
                ->rows(2)
                ->placeholder('z. B. Formular vom …, E-Mail vom …, Gesprächsnotiz')
                ->requiredWith('phone_consent_at')
                ->columnSpanFull(),
            DatePicker::make('health_consent_at')
                ->label('Einwilligung Gesundheitsangaben am')
                ->maxDate(today())
                ->helperText('Nur für Zielgruppe E (Arbeitsunfall). Keine medizinischen Angaben in Notizen.')
                ->requiredWith('health_consent_proof'),
            Textarea::make('health_consent_proof')
                ->label('Nachweis Einwilligung Gesundheitsangaben')
                ->rows(2)
                ->requiredWith('health_consent_at')
                ->columnSpanFull(),
        ];
    }
}
