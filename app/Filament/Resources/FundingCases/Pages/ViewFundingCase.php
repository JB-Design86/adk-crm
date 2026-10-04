<?php

namespace App\Filament\Resources\FundingCases\Pages;

use App\Filament\Documents\DocumentTable;
use App\Filament\Resources\FundingCases\FundingCaseResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Participants\ParticipantResource;
use App\Models\FundingCase;
use App\Models\FundingCaseStep;
use App\Models\FundingStep;
use App\Services\Documents\DocumentService;
use App\Services\FundingService;
use App\Services\ParticipantService;
use App\Support\Adk;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Förderfall mit Schrittfolge, Erklärtext zum aktuellen Schritt und Stammdaten.
 *
 * @property FundingCase $record
 */
class ViewFundingCase extends ViewRecord
{
    protected static string $resource = FundingCaseResource::class;

    protected string $view = 'filament.resources.funding-cases.view';

    public function getTitle(): string
    {
        return $this->record->lead->displayName();
    }

    public function getSubheading(): string
    {
        return $this->record->pathwayLabel().' · '.$this->record->stateLabel();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->completeStepAction(),
            Action::make('enroll')
                ->label('Einschreibung bestätigt')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn () => Gate::allows('leads.edit') && $this->record->isOpen() && $this->record->allStepsDone())
                ->modalHeading('Einschreibung bestätigt?')
                ->modalDescription(fn () => $this->record->missingDocuments()
                    ? 'Es fehlen noch Pflichtunterlagen: '.implode(', ', array_map(fn ($c) => Adk::documentCategoryLabel($c), array_keys($this->record->missingDocuments()))).'. Bitte zuerst unten unter „Dokumente“ hochladen.'
                    : ($this->record->pathway === 'employer'
                        ? 'Nur bestätigen, wenn der Kostenträger die Anmeldung bestätigt hat. Der Betrieb wird Firmenkunde. Die Teilnehmenden legen Sie danach hier im Förderfall einzeln an.'
                        : 'Nur bestätigen, wenn die Person offiziell eingeschrieben ist und der Kostenträger die Anmeldung bestätigt hat. Das CRM legt dann die Teilnehmerakte an.'))
                ->modalSubmitActionLabel('Bestätigen')
                ->schema(fn () => $this->record->pathway === 'employer' ? [] : [
                    Section::make('Für die Teilnehmerakte')
                        ->description('Kann auch später in der Akte ergänzt werden.')
                        ->columns(2)
                        ->schema([
                            TextInput::make('course_name')->label('Kurs bzw. Maßnahme')->maxLength(255)->columnSpanFull(),
                            DatePicker::make('course_starts_on')->label('Kursbeginn'),
                            DatePicker::make('course_ends_on')->label('geplantes Kursende')->afterOrEqual('course_starts_on'),
                            DatePicker::make('birth_date')->label('Geburtsdatum')->maxDate(today()->subYears(14)),
                            TextInput::make('street')->label('Straße und Hausnummer')->maxLength(255),
                            TextInput::make('postal_code')->label('PLZ')->maxLength(10),
                            TextInput::make('city')->label('Ort')->maxLength(255),
                        ]),
                ])
                ->action(function (array $data, Action $action) {
                    try {
                        $participant = app(FundingService::class)->enroll($this->record, data: $data);
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Noch nicht möglich')->body(implode(' ', $exception->validator->errors()->all()))->danger()->persistent()->send();
                        $action->halt();

                        return;
                    }

                    if ($participant && Gate::allows('participants')) {
                        Notification::make()->title('Teilnehmerakte angelegt')->body($participant->number)->success()->send();

                        return redirect(ParticipantResource::getUrl('view', ['record' => $participant]));
                    }

                    Notification::make()->title('Einschreibung gespeichert')->success()->send();
                }),
            Action::make('participantFile')
                ->label(fn () => $this->record->participants()->count() > 1 ? 'Teilnehmerakten' : 'Teilnehmerakte')
                ->icon(Heroicon::OutlinedIdentification)
                ->color('success')
                ->visible(fn () => Gate::allows('participants') && $this->record->participants()->exists())
                ->url(fn () => $this->record->participants()->count() === 1
                    ? ParticipantResource::getUrl('view', ['record' => $this->record->participants()->value('id')])
                    : ParticipantResource::getUrl('index', ['tableSearch' => $this->record->lead->organization?->name])),
            Action::make('addEmployee')
                ->label('Teilnehmer/in anlegen')
                ->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn () => Gate::allows('participants') && $this->record->state === 'enrolled' && $this->record->pathway === 'employer')
                ->modalHeading('Beschäftigte/n als Teilnehmer/in anlegen')
                ->schema([
                    Grid::make(3)->schema([
                        Select::make('salutation')->label('Anrede')->options(['Frau' => 'Frau', 'Herr' => 'Herr', 'divers' => 'divers']),
                        TextInput::make('first_name')->label('Vorname')->maxLength(255),
                        TextInput::make('last_name')->label('Nachname')->required()->maxLength(255),
                    ]),
                    Grid::make(2)->schema([
                        TextInput::make('email')->label('E-Mail')->email()->maxLength(255),
                        TextInput::make('phone_display')->label('Telefon')->maxLength(40),
                        DatePicker::make('birth_date')->label('Geburtsdatum')->maxDate(today()->subYears(14)),
                        TextInput::make('course_name')->label('Kurs bzw. Maßnahme')->maxLength(255),
                        DatePicker::make('course_starts_on')->label('Kursbeginn'),
                        DatePicker::make('course_ends_on')->label('geplantes Kursende')->afterOrEqual('course_starts_on'),
                    ]),
                ])
                ->action(function (array $data) {
                    $participant = app(ParticipantService::class)->addEmployee($this->record, $data);
                    Notification::make()->title('Teilnehmerakte angelegt')->body($participant->number.' · '.$participant->displayName())->success()->send();
                }),
            Action::make('editData')
                ->label('Daten')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->visible(fn () => Gate::allows('leads.edit'))
                ->fillForm(fn () => $this->record->only(['customer_number', 'voucher_number', 'voucher_valid_until', 'funder_contact', 'notes']))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('customer_number')->label('Kundennummer beim Kostenträger')->maxLength(255),
                        TextInput::make('funder_contact')->label('Ansprechperson beim Kostenträger')->maxLength(255),
                        TextInput::make('voucher_number')->label('Gutscheinnummer')->maxLength(255),
                        DatePicker::make('voucher_valid_until')->label('Gutschein gültig bis'),
                    ]),
                    Textarea::make('notes')->label('Notizen zum Förderfall')->rows(3),
                ])
                ->action(function (array $data) {
                    $this->record->update($data);
                    Notification::make()->title('Gespeichert')->success()->send();
                }),
            Action::make('cancel')
                ->label('Abbrechen')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('gray')
                ->visible(fn () => Gate::allows('leads.edit') && $this->record->isOpen())
                ->modalHeading('Förderweg abbrechen')
                ->schema([
                    TextInput::make('reason')->label('Grund')->required()->maxLength(255),
                    Radio::make('outcome')
                        ->label('Wie geht es mit dem Vorgang weiter?')
                        ->options(['later' => 'Später erneut ansprechen (Wiedervorlage)', 'no_interest' => 'Vorgang schließen (kein Interesse)'])
                        ->default('later')
                        ->live()
                        ->required(),
                    DatePicker::make('follow_up_at')
                        ->label('Wiedervorlage am')
                        ->minDate(today())
                        ->visible(fn (Get $get) => $get('outcome') === 'later')
                        ->required(fn (Get $get) => $get('outcome') === 'later'),
                ])
                ->action(function (array $data) {
                    app(FundingService::class)->cancel($this->record, $data['reason'], $data['outcome'], $data['follow_up_at'] ?? null);
                    Notification::make()->title('Förderweg abgebrochen')->success()->send();
                }),
            Action::make('openLead')
                ->label('Vorgang')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn () => LeadResource::getUrl('view', ['record' => $this->record->lead_id])),
        ];
    }

    public function completeStepAction(): Action
    {
        return Action::make('completeStep')
            ->label('Schritt erledigen')
            ->icon(Heroicon::OutlinedCheck)
            ->visible(fn () => Gate::allows('leads.edit') && $this->record->isOpen() && ! $this->record->allStepsDone())
            ->modalHeading('Schritt erledigen')
            ->fillForm(fn () => [
                'step' => $this->record->currentStep()?->id,
                'completed_on' => today()->toDateString(),
                'result' => 'done',
                'party' => $this->record->currentStep()?->default_party,
                'category' => $this->record->currentStep()?->document_category,
            ])
            ->schema([
                Select::make('step')
                    ->label('Schritt')
                    ->options(fn () => $this->openSteps())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state, callable $set) => $set('category', FundingStep::find($state)?->document_category))
                    ->helperText(fn (Get $get) => FundingStep::find($get('step'))?->instructions),
                Grid::make(2)->schema([
                    DatePicker::make('completed_on')->label('erledigt am')->maxDate(today())->required(),
                    Select::make('party')->label('Wer hat gehandelt?')->options(config('adk.funding_parties')),
                ]),
                ToggleButtons::make('result')
                    ->label('Ergebnis')
                    ->options(FundingCaseStep::RESULTS)
                    ->colors(['done' => 'success', 'rejected' => 'danger'])
                    ->inline()
                    ->visible(fn (Get $get) => (bool) FundingStep::find($get('step'))?->can_fail)
                    ->default('done'),
                Textarea::make('note')->label('Notiz')->rows(2)->helperText('Keine medizinischen Angaben.'),
                Section::make('Unterlage dazu')
                    ->description(fn (Get $get) => ($step = FundingStep::find($get('step')))?->requires_document
                        ? 'Pflicht vor der Einschreibung: '.Adk::documentCategoryLabel($step->document_category).'. Sie können sie jetzt oder später hochladen.'
                        : 'Optional.')
                    ->compact()
                    ->schema(collect(DocumentTable::uploadSchema(required: false))
                        ->reject(fn ($field) => in_array($field->getName(), ['document_date', 'notes'], true))
                        ->values()
                        ->all()),
            ])
            ->action(function (array $data, Action $action) {
                try {
                    $step = FundingStep::findOrFail($data['step']);

                    // Erst der Schritt, dann die Datei: Scheitert das Hochladen, wird auch der Schritt nicht gespeichert.
                    $record = DB::transaction(function () use ($step, $data) {
                        $record = app(FundingService::class)->completeStep($this->record, $step, $data);

                        if ($data['file'] ?? null) {
                            app(DocumentService::class)->store($this->record->lead, $data['file'], [
                                'category' => $data['category'] ?? $step->document_category ?? 'other',
                                'title' => $data['title'] ?? $step->name,
                                'document_date' => $data['completed_on'] ?? null,
                            ]);
                        }

                        return $record;
                    });
                } catch (ValidationException $exception) {
                    Notification::make()->title('Nicht gespeichert')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();
                    $action->halt();

                    return;
                }

                $this->record->refresh();

                if ($record->result === 'rejected') {
                    Notification::make()
                        ->title('Abgelehnt eingetragen')
                        ->body('Bitte entscheiden Sie, wie es weitergeht: Widerspruch bzw. neuer Antrag, oder Förderweg abbrechen.')
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Schritt erledigt')
                    ->body($this->record->allStepsDone()
                        ? 'Alle Schritte erledigt. Nach Bestätigung durch den Kostenträger bitte „Einschreibung bestätigt“ wählen.'
                        : 'Nächster Schritt: '.$this->record->currentStep()?->name.'. Wiedervorlage am '.$this->record->lead->fresh()->next_action_at?->format('d.m.Y').'.')
                    ->success()
                    ->send();
            });
    }

    /** Noch nicht erledigte Schritte, der aktuelle zuerst. */
    private function openSteps(): array
    {
        $done = $this->record->completedSteps()->pluck('funding_step_id')->all();

        return $this->record->steps()
            ->reject(fn (FundingStep $step) => in_array($step->id, $done, true))
            ->mapWithKeys(fn (FundingStep $step) => [$step->id => $step->name])
            ->all();
    }

    public function undoStep(int $recordId): void
    {
        Gate::authorize('leads.edit');

        $record = $this->record->completedSteps()->findOrFail($recordId);
        app(FundingService::class)->undoStep($record);
        $this->record->refresh();

        Notification::make()->title('Schritt zurückgenommen')->success()->send();
    }
}
