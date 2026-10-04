<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Filament\Documents\DocumentTable;
use App\Models\Lead;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Dokumente des Vorgangs: Anfrage, Förderfall und, mit Recht, die der Teilnehmerakte.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Dokumente';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('documents');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) DocumentTable::visible($ownerRecord->documents()->getQuery())->count();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var Lead $lead */
        $lead = $this->getOwnerRecord();

        return DocumentTable::configure($table, fn () => $lead);
    }
}
