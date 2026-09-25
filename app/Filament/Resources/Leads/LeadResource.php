<?php

namespace App\Filament\Resources\Leads;

use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Leads\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Leads\RelationManagers\AppointmentsRelationManager;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Filament\Resources\Leads\Schemas\LeadInfolist;
use App\Filament\Resources\Leads\Tables\LeadsTable;
use App\Models\Lead;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Akquise';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Vorgang';

    protected static ?string $pluralModelLabel = 'Vorgänge';

    protected static ?string $slug = 'vorgaenge';

    public static function canViewAny(): bool
    {
        return Gate::allows('leads.view');
    }

    public static function canView(Model $record): bool
    {
        return Gate::allows('leads.view');
    }

    public static function canCreate(): bool
    {
        return Gate::allows('leads.edit');
    }

    public static function canEdit(Model $record): bool
    {
        return Gate::allows('leads.edit');
    }

    /** Gelöscht wird nur durch den Löschlauf. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return LeadForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LeadInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeadsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ActivitiesRelationManager::class,
            AppointmentsRelationManager::class,
        ];
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record?->displayName() ?? 'Vorgang';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'create' => CreateLead::route('/create'),
            'view' => ViewLead::route('/{record}'),
            'edit' => EditLead::route('/{record}/edit'),
        ];
    }
}
