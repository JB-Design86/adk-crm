<?php

namespace App\Filament\Resources\EmailTemplates;

use App\Filament\Resources\EmailTemplates\Pages\CreateEmailTemplate;
use App\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Filament\Resources\EmailTemplates\Pages\ListEmailTemplates;
use App\Models\EmailTemplate;
use App\Services\Microsoft\GraphMailer;
use App\Support\Hilfe;
use App\Support\MailHtml;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Vorlagen für „E-Mail schreiben“ im Vorgang: anlegen, sortieren, abschalten, löschen.
 * Nur Verwaltung. Änderungen stehen im Protokoll.
 */
class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Verwaltung';

    protected static ?int $navigationSort = 28;

    protected static ?string $modelLabel = 'E-Mail-Vorlage';

    protected static ?string $pluralModelLabel = 'E-Mail-Vorlagen';

    protected static ?string $slug = 'e-mail-vorlagen';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return Gate::allows('settings.manage');
    }

    public static function canCreate(): bool
    {
        return Gate::allows('settings.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Gate::allows('settings.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return Gate::allows('settings.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Vorlage')
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->label('Bezeichnung')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Erscheint in „E-Mail schreiben“ in der Auswahl „Vorlage“.'),
                    TextInput::make('subject')
                        ->label('Betreff')
                        ->required()
                        ->maxLength(255),
                    RichEditor::make('body')
                        ->label('Text')
                        ->toolbarButtons(MailHtml::TOOLBAR)
                        ->fileAttachments(false)
                        ->minHeight('16rem')
                        ->required()
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, tooltip: Hilfe::feld('template_body'))
                        ->helperText(EmailTemplate::placeholderHelp().' Die Signatur der sendenden Person hängt das CRM an.'),
                    FileUpload::make('attachment_path')
                        ->label('Anhang (optional)')
                        ->disk(EmailTemplate::DISK)
                        ->directory(EmailTemplate::DIRECTORY)
                        ->visibility('private')
                        ->storeFileNamesIn('attachment_name')
                        ->maxSize((int) (GraphMailer::MAX_ATTACHMENT_BYTES / 1024))
                        ->acceptedFileTypes(EmailTemplate::ATTACHMENT_TYPES)
                        ->helperText('Eine Datei, z. B. das Kursheft als PDF, höchstens 3 MB (Grenze von Microsoft 365 beim direkten Versand). Keine personenbezogenen Unterlagen.'),
                    Toggle::make('is_active')
                        ->label('aktiv')
                        ->default(true)
                        ->helperText('Inaktive Vorlagen erscheinen nicht mehr in „E-Mail schreiben“.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->reorderRecordsTriggerAction(fn ($action, bool $isReordering) => $action->label($isReordering ? 'Reihenfolge fertig' : 'Reihenfolge ändern'))
            ->columns([
                TextColumn::make('sort_order')->label('Nr.')->alignCenter(),
                TextColumn::make('name')->label('Bezeichnung')->weight('medium')->searchable(),
                TextColumn::make('subject')->label('Betreff')->wrap(),
                TextColumn::make('attachment_name')->label('Anhang')->placeholder('–'),
                ToggleColumn::make('is_active')->label('aktiv'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Die Vorlage und ihr Anhang werden gelöscht. Bereits gesendete E-Mails bleiben im Verlauf der Vorgänge. Wenn Sie die Vorlage nur nicht mehr anbieten möchten, schalten Sie sie auf inaktiv.'),
            ])
            ->emptyStateHeading('Noch keine E-Mail-Vorlagen');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailTemplates::route('/'),
            'create' => CreateEmailTemplate::route('/create'),
            'edit' => EditEmailTemplate::route('/{record}/edit'),
        ];
    }
}
