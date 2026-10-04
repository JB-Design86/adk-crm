<?php

namespace App\Filament\Resources\DuplicateCandidates;

use App\Filament\Resources\DuplicateCandidates\Pages\ListDuplicateCandidates;
use App\Models\Contact;
use App\Models\DuplicateCandidate;
use App\Models\Organization;
use App\Services\Duplicates\DuplicateFinder;
use App\Services\Duplicates\DuplicateResolver;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Dublettenprüfung: Verdachtsfälle vergleichen und entscheiden.
 */
class DuplicateCandidateResource extends Resource
{
    protected static ?string $model = DuplicateCandidate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Stammdaten';

    protected static ?int $navigationSort = 15;

    protected static ?string $modelLabel = 'Dublettenverdacht';

    protected static ?string $pluralModelLabel = 'Dublettenprüfung';

    protected static ?string $slug = 'dubletten';

    public static function canViewAny(): bool
    {
        return Gate::allows('duplicates');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = DuplicateCandidate::open()->count();

        return $open ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'offene Verdachtsfälle';
    }

    public static function subject(DuplicateCandidate $candidate): ?Model
    {
        return $candidate->subjectRecord();
    }

    public static function match(DuplicateCandidate $candidate): ?Model
    {
        return $candidate->matchRecord();
    }

    /** Telefon, E-Mail, Website bzw. Anschrift in einer Zeile. */
    public static function details(?Model $record): ?string
    {
        return match (true) {
            $record instanceof Organization => collect([$record->street, $record->phone_display, $record->email, $record->website])->filter()->implode(' · ') ?: null,
            $record instanceof Contact => collect([$record->position, $record->phone_display, $record->email])->filter()->implode(' · ') ?: null,
            default => null,
        };
    }

    /** Dialog beim Zusammenführen: je abweichendem Feld behalten oder übernehmen. */
    public static function mergeSchema(DuplicateCandidate $record): array
    {
        $match = self::match($record);
        $subject = self::subject($record);

        if (! $match || ! $subject) {
            return [];
        }

        $diff = DuplicateResolver::differences($match, $subject, auth()->user());

        $choices = collect($diff['conflicts'])
            ->map(fn (array $conflict, string $field) => Radio::make("choices.{$field}")
                ->label($conflict['label'])
                ->options([
                    'match' => 'behalten: '.$conflict['match'],
                    'subject' => 'übernehmen: '.$conflict['subject'],
                ])
                ->default('match')
                ->required())
            ->values()
            ->all();

        $subjectName = $subject instanceof Organization ? $subject->name : $subject->fullName();

        return [
            Section::make('Unterschiedliche Angaben')
                ->description($choices ? 'Links der vorhandene Eintrag, rechts der neuere.' : null)
                ->schema($choices ?: [Text::make('Keine abweichenden Angaben.')]),
            Section::make('Wird automatisch ergänzt')
                ->description('Beim vorhandenen Eintrag leer, beim neueren gefüllt.')
                ->visible($diff['fills'] !== [])
                ->schema([Text::make(implode(' · ', $diff['fills']))]),
            Section::make('Wird gelöscht')
                ->schema([Text::make("Der neuere Eintrag „{$subjectName}“ samt den nicht gewählten Werten. "
                    .($subject instanceof Organization
                        ? 'Seine Kontakte ziehen zum vorhandenen Eintrag um (gleiche Personen werden zusammengelegt). Vorgänge mit Verlauf ziehen um, ein frisch importierter Vorgang ohne Verlauf entfällt.'
                        : 'Seine Vorgänge und Teilnehmerakten ziehen zum vorhandenen Kontakt um.'))]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('importLog'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('subject_id')
                    ->label('Neu bzw. später angelegt')
                    ->state(fn (DuplicateCandidate $record) => ($subject = self::subject($record)) ? DuplicateFinder::describe($subject) : 'gelöscht')
                    ->description(fn (DuplicateCandidate $record) => self::details(self::subject($record)))
                    ->weight('medium')
                    ->wrap(),
                TextColumn::make('match_id')
                    ->label('Vorhanden')
                    ->state(fn (DuplicateCandidate $record) => ($match = self::match($record)) ? DuplicateFinder::describe($match) : 'gelöscht')
                    ->description(fn (DuplicateCandidate $record) => self::details(self::match($record)))
                    ->wrap(),
                TextColumn::make('reasons')
                    ->label('Übereinstimmung')
                    ->state(fn (DuplicateCandidate $record) => implode(', ', $record->reasons))
                    ->description(fn (DuplicateCandidate $record) => $record->score >= 100 ? 'sicher' : "Ähnlichkeit {$record->score} von 100")
                    ->wrap(),
                TextColumn::make('type')
                    ->label('Art')
                    ->formatStateUsing(fn (string $state) => $state === 'organization' ? 'Organisation' : 'Kontakt')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('created_at')
                    ->label('gefunden')
                    ->dateTime('d.m.Y')
                    ->description(fn (DuplicateCandidate $record) => $record->importLog ? 'Import '.$record->importLog->file_name : 'Eingabe bzw. Bestandsprüfung'),
                TextColumn::make('state')
                    ->label('Stand')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => DuplicateCandidate::STATES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'open' => 'warning',
                        'merged' => 'success',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('state')->label('Stand')->options(DuplicateCandidate::STATES)->default('open'),
                SelectFilter::make('type')->label('Art')->options(['organization' => 'Organisation', 'contact' => 'Kontakt']),
            ])
            ->recordActions([
                Action::make('merge')
                    ->label('Derselbe: zusammenführen')
                    ->icon(Heroicon::OutlinedArrowsPointingIn)
                    ->color('success')
                    ->visible(fn (DuplicateCandidate $record) => $record->state === 'open' && self::subject($record) && self::match($record))
                    ->modalHeading('Zusammenführen: was bleibt?')
                    ->modalDescription(fn (DuplicateCandidate $record) => 'Bestehen bleibt der vorhandene Eintrag „'.DuplicateFinder::describe(self::match($record)).'“ mit seinem Verlauf. '
                        .'Wählen Sie bei unterschiedlichen Angaben, welcher Wert bleibt. Nicht gewählte Werte werden gelöscht.')
                    ->modalWidth('2xl')
                    ->schema(fn (DuplicateCandidate $record) => self::mergeSchema($record))
                    ->modalSubmitActionLabel('Zusammenführen')
                    ->action(function (DuplicateCandidate $record, array $data) {
                        app(DuplicateResolver::class)->merge($record, choices: $data['choices'] ?? []);
                        Notification::make()->title('Zusammengeführt')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Verschieden: freigeben')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('gray')
                    ->visible(fn (DuplicateCandidate $record) => $record->state === 'open')
                    ->requiresConfirmation()
                    ->modalHeading('Kein Duplikat?')
                    ->modalDescription('Beide Einträge bleiben. Dieses Paar wird nicht wieder gemeldet, und der Eintrag erscheint wieder in der Anrufliste.')
                    ->modalSubmitActionLabel('Freigeben')
                    ->action(function (DuplicateCandidate $record) {
                        app(DuplicateResolver::class)->reject($record);
                        Notification::make()->title('Freigegeben')->success()->send();
                    }),
            ])
            ->emptyStateHeading('Keine offenen Verdachtsfälle')
            ->emptyStateDescription('Verdachtsfälle entstehen beim Import und beim Anlegen von Organisationen und Kontakten. Mit „Bestand prüfen“ lässt sich der ganze Bestand durchsehen.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDuplicateCandidates::route('/'),
        ];
    }
}
