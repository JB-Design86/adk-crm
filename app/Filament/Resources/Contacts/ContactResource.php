<?php

namespace App\Filament\Resources\Contacts;

use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Schemas\ContactForm;
use App\Models\Contact;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Kontakt';

    protected static ?string $pluralModelLabel = 'Kontakte';

    protected static ?string $slug = 'kontakte';

    public static function canViewAny(): bool
    {
        return Gate::allows('leads.view');
    }

    public static function canCreate(): bool
    {
        return Gate::allows('contacts.edit');
    }

    public static function canEdit(Model $record): bool
    {
        return Gate::allows('contacts.edit');
    }

    /** Gelöscht wird nur durch den Löschlauf. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record?->fullName() ?? 'Kontakt';
    }

    public static function form(Schema $schema): Schema
    {
        return ContactForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('organization'))
            ->columns([
                TextColumn::make('last_name')
                    ->label('Name')
                    ->formatStateUsing(fn (Contact $record) => $record->fullName())
                    ->searchable(['first_name', 'last_name'])
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('organization.name')->label('Organisation')->placeholder('–'),
                TextColumn::make('phone_display')->label('Telefon')->searchable(['phone_display', 'phone_e164']),
                TextColumn::make('email')->label('E-Mail')->searchable(),
                IconColumn::make('is_private')->label('privat')->boolean(),
                TextColumn::make('privacy_notice_sent_at')->label('DS-Hinweis')->date('d.m.Y')->placeholder('–'),
                TextColumn::make('phone_consent_at')->label('Einwilligung Tel.')->date('d.m.Y')->placeholder('–'),
            ])
            ->filters([
                TernaryFilter::make('is_private')->label('Privatperson'),
                TernaryFilter::make('phone_consent')
                    ->label('Einwilligung Telefonansprache')
                    ->nullable()
                    ->attribute('phone_consent_at'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
            'create' => CreateContact::route('/create'),
            'edit' => EditContact::route('/{record}/edit'),
        ];
    }
}
