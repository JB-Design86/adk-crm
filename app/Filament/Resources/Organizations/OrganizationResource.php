<?php

namespace App\Filament\Resources\Organizations;

use App\Filament\Resources\Organizations\Pages\CreateOrganization;
use App\Filament\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Schemas\OrganizationForm;
use App\Models\Organization;
use App\Support\Adk;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Organisation';

    protected static ?string $pluralModelLabel = 'Organisationen';

    protected static ?string $slug = 'organisationen';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return Gate::allows('leads.view');
    }

    public static function canCreate(): bool
    {
        return Gate::allows('organizations.edit');
    }

    public static function canEdit(Model $record): bool
    {
        return Gate::allows('organizations.edit');
    }

    /** Gelöscht wird nur durch den Löschlauf. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return OrganizationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('priority')->label('Prio')->badge()->color('gray')->sortable(),
                TextColumn::make('industry')->label('Branche')->toggleable(),
                TextColumn::make('postal_code')->label('PLZ')->searchable(),
                TextColumn::make('city')->label('Ort')->searchable()->sortable(),
                TextColumn::make('phone_display')->label('Telefon')->searchable(['phone_display', 'phone_e164']),
                TextColumn::make('source')->label('Quelle')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('retrieved_at')->label('Abrufdatum')->date('d.m.Y')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('leads_count')->label('Vorgänge')->counts('leads')->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('priority')->label('Priorität')->options(Adk::priorityOptions())->multiple(),
                SelectFilter::make('industry')->label('Branche')->options(Adk::industryOptions())->multiple(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'create' => CreateOrganization::route('/create'),
            'edit' => EditOrganization::route('/{record}/edit'),
        ];
    }
}
