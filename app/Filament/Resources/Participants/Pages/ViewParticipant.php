<?php

namespace App\Filament\Resources\Participants\Pages;

use App\Filament\Documents\DocumentTable;
use App\Filament\Resources\FundingCases\FundingCaseResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Participants\ParticipantResource;
use App\Models\Participant;
use App\Models\ParticipantChecklistItem;
use App\Services\ParticipantArchive;
use App\Services\ParticipantService;
use App\Support\Adk;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Teilnehmerakte: Checkliste nach Lastenheft 7.1 mit Datum und Dokument je Punkt,
 * Vertragsdaten, Kurs, Abschluss, Verbleib und alle Dokumente.
 *
 * @property Participant $record
 */
class ViewParticipant extends ViewRecord
{
    protected static string $resource = ParticipantResource::class;

    protected string $view = 'filament.resources.participants.view';

    protected function getListeners(): array
    {
        return [...parent::getListeners(), 'documents-changed' => '$refresh'];
    }

    public function getTitle(): string
    {
        return $this->record->displayName();
    }

    public function getSubheading(): string
    {
        return $this->record->number.' · '.$this->record->stateLabel()
            .($this->record->fundingCase ? ' · '.$this->record->fundingCase->pathwayLabel() : '');
    }

    /** Für den Vertrag fehlende Angaben. @return list<string> */
    public function missingContractData(): array
    {
        $p = $this->record;

        return array_keys(array_filter([
            'Geburtsdatum' => ! $p->birth_date,
            'Anschrift' => ! $p->street || ! $p->postal_code || ! $p->city,
            'Kurs' => ! $p->course_name,
            'Kursbeginn' => ! $p->course_starts_on,
        ]));
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->completeCheckAction(),
            Action::make('editData')
                ->label('Daten')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->fillForm(fn () => $this->record->only([...ParticipantService::FIELDS, 'accommodation_notes']))
                ->schema([
                    Section::make('Für den Vertrag')->columns(2)->schema([
                        DatePicker::make('birth_date')->label('Geburtsdatum')->maxDate(today()->subYears(14)),
                        TextInput::make('street')->label('Straße und Hausnummer')->maxLength(255),
                        TextInput::make('postal_code')->label('PLZ')->maxLength(10),
                        TextInput::make('city')->label('Ort')->maxLength(255),
                    ]),
                    Section::make('Kurs')->columns(3)->schema([
                        TextInput::make('course_name')->label('Kurs bzw. Maßnahme')->maxLength(255)->columnSpanFull(),
                        DatePicker::make('course_starts_on')->label('Beginn'),
                        DatePicker::make('course_ends_on')->label('geplantes Ende')->afterOrEqual('course_starts_on'),
                    ]),
                    Textarea::make('notes')->label('Notizen')->rows(3)->helperText('Keine Gesundheitsangaben.'),
                    Textarea::make('accommodation_notes')
                        ->label('Nachteilsausgleich (nur Verwaltung)')
                        ->rows(2)
                        ->visible(fn () => Gate::allows('health.view'))
                        ->helperText('Nur die vereinbarte Regelung, keine Diagnosen. Verschlüsselt gespeichert.'),
                ])
                ->action(function (array $data) {
                    if (! Gate::allows('health.view')) {
                        unset($data['accommodation_notes']);
                    }

                    $this->record->update($data);
                    Notification::make()->title('Gespeichert')->success()->send();
                }),
            Action::make('finish')
                ->label('Abschluss erfassen')
                ->icon(Heroicon::OutlinedFlag)
                ->color('success')
                ->visible(fn () => ! $this->record->isFinished())
                ->modalHeading('Kurs beendet')
                ->modalDescription('Danach setzt das CRM die Wiedervorlage für die Verbleibserhebung, '.config('adk.placement_follow_up_months').' Monate nach dem letzten Kurstag.')
                ->fillForm(fn () => ['outcome' => 'completed', 'left_on' => ($this->record->course_ends_on ?? today())->toDateString()])
                ->schema([
                    Radio::make('outcome')->label('Ergebnis')->options(['completed' => 'abgeschlossen', 'dropped' => 'abgebrochen'])->required()->live(),
                    DatePicker::make('left_on')->label('letzter Kurstag')->required(),
                    TextInput::make('exit_reason')
                        ->label('Grund des Abbruchs')
                        ->maxLength(255)
                        ->visible(fn (Get $get) => $get('outcome') === 'dropped')
                        ->required(fn (Get $get) => $get('outcome') === 'dropped')
                        ->helperText('Ohne Gesundheitsangaben, z. B. „Arbeitsaufnahme“.'),
                ])
                ->action(function (array $data, Action $action) {
                    try {
                        app(ParticipantService::class)->finish($this->record, $data['outcome'], $data['left_on'], $data['exit_reason'] ?? null);
                    } catch (ValidationException $exception) {
                        $this->notifyInvalid($exception, $action);

                        return;
                    }

                    Notification::make()->title('Abschluss gespeichert')->body('Verbleibserhebung am '.$this->record->fresh()->follow_up_on?->format('d.m.Y').'.')->success()->send();
                }),
            Action::make('placement')
                ->label('Verbleib erfassen')
                ->icon(Heroicon::OutlinedBriefcase)
                ->color('success')
                ->visible(fn () => $this->record->isFinished() && ! $this->record->placement_status)
                ->fillForm(fn () => ['recorded_on' => today()->toDateString()])
                ->schema([
                    Select::make('placement_status')->label('Verbleib')->options(config('adk.placement_statuses'))->required(),
                    DatePicker::make('recorded_on')->label('erhoben am')->maxDate(today())->required(),
                ])
                ->action(function (array $data) {
                    app(ParticipantService::class)->recordPlacement($this->record, $data['placement_status'], $data['recorded_on']);
                    Notification::make()->title('Verbleib gespeichert')->success()->send();
                }),
            ActionGroup::make([
                Action::make('archive')
                    ->label('Akte als ZIP ausgeben')
                    ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                    ->action(function () {
                        $path = app(ParticipantArchive::class)->build($this->record);

                        return response()->download($path, $this->record->number.'_Teilnehmerakte.zip')->deleteFileAfterSend();
                    }),
                Action::make('openFundingCase')
                    ->label('Förderfall')
                    ->icon(Heroicon::OutlinedAcademicCap)
                    ->visible(fn () => $this->record->funding_case_id !== null)
                    ->url(fn () => FundingCaseResource::getUrl('view', ['record' => $this->record->funding_case_id])),
                Action::make('openLead')
                    ->label('Vorgang')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn () => LeadResource::getUrl('view', ['record' => $this->record->lead_id])),
            ])->label('Mehr')->icon(Heroicon::OutlinedEllipsisVertical)->color('gray')->button(),
        ];
    }

    public function completeCheckAction(): Action
    {
        return Action::make('completeCheck')
            ->label('Punkt abhaken')
            ->icon(Heroicon::OutlinedCheck)
            ->modalHeading('Punkt der Checkliste abhaken')
            ->fillForm(fn (array $arguments) => [
                'item' => $arguments['item'] ?? $this->openItems()->keys()->first(),
                'done_on' => today()->toDateString(),
                'category' => ParticipantChecklistItem::find($arguments['item'] ?? $this->openItems()->keys()->first())?->document_category,
            ])
            ->schema([
                Select::make('item')
                    ->label('Punkt')
                    ->options(fn () => $this->openItems()->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state, callable $set) => $set('category', ParticipantChecklistItem::find($state)?->document_category))
                    ->helperText(fn (Get $get) => ParticipantChecklistItem::find($get('item'))?->instructions),
                DatePicker::make('done_on')->label('erledigt am')->maxDate(today())->required(),
                Textarea::make('note')->label('Notiz')->rows(2),
                Section::make('Dokument dazu (optional)')
                    ->compact()
                    ->collapsible()
                    ->schema(collect(DocumentTable::uploadSchema(required: false))
                        ->reject(fn ($field) => in_array($field->getName(), ['document_date', 'notes'], true))
                        ->values()
                        ->all()),
            ])
            ->action(function (array $data, Action $action) {
                try {
                    app(ParticipantService::class)->completeCheck($this->record, ParticipantChecklistItem::findOrFail($data['item']), $data);
                } catch (ValidationException $exception) {
                    $this->notifyInvalid($exception, $action);

                    return;
                }

                $this->record->refresh();
                Notification::make()->title('Abgehakt')->success()->send();
            });
    }

    /** Offene Punkte zuerst, wiederholbare immer. @return \Illuminate\Support\Collection<int, string> */
    private function openItems(): Collection
    {
        $done = $this->record->checks()->pluck('participant_checklist_item_id')->all();

        return ParticipantChecklistItem::query()->active()->get()
            ->reject(fn (ParticipantChecklistItem $item) => ! $item->repeatable && in_array($item->id, $done, true))
            ->mapWithKeys(fn (ParticipantChecklistItem $item) => [$item->id => $item->phaseLabel().': '.$item->name]);
    }

    public function undoCheck(int $checkId): void
    {
        Gate::authorize('participants');

        app(ParticipantService::class)->undoCheck($this->record->checks()->findOrFail($checkId));
        $this->record->refresh();
        Notification::make()->title('Zurückgenommen')->success()->send();
    }

    private function notifyInvalid(ValidationException $exception, Action $action): void
    {
        Notification::make()->title('Nicht gespeichert')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();
        $action->halt();
    }

    public static function stateColor(string $state): string
    {
        return Adk::participantStateColor($state);
    }
}
