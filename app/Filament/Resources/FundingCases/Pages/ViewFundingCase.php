<?php

namespace App\Filament\Resources\FundingCases\Pages;

use App\Filament\Resources\FundingCases\FundingCaseResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\FundingCase;
use App\Models\FundingCaseStep;
use App\Models\FundingStep;
use App\Services\FundingService;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
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
                ->requiresConfirmation()
                ->modalHeading('Einschreibung bestätigt?')
                ->modalDescription('Nur bestätigen, wenn die Person offiziell eingeschrieben ist und der Kostenträger die Anmeldung bestätigt hat. Aus dem Förderfall wird ein Teilnehmer, die Teilnehmerakte folgt in Stufe 4.')
                ->action(function () {
                    app(FundingService::class)->enroll($this->record);
                    Notification::make()->title('Als Teilnehmer übernommen')->success()->send();
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
            ])
            ->schema([
                Select::make('step')
                    ->label('Schritt')
                    ->options(fn () => $this->openSteps())
                    ->required()
                    ->live()
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
            ])
            ->action(function (array $data, Action $action) {
                try {
                    $record = app(FundingService::class)->completeStep(
                        $this->record,
                        FundingStep::findOrFail($data['step']),
                        $data,
                    );
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
