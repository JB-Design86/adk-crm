<?php

namespace App\Filament\Actions;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadStatusService;
use App\Support\Adk;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Aktionen an einem Vorgang, gemeinsam genutzt von Vorgangsseite und Heute-Ansicht.
 */
class LeadActions
{
    public static function setStatus(): Action
    {
        return Action::make('setStatus')
            ->label('Status setzen')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn () => Gate::allows('leads.edit'))
            ->modalHeading(fn (Lead $record) => 'Status setzen: '.$record->displayName())
            ->modalSubmitActionLabel('Speichern')
            ->schema(fn (Lead $record) => static::statusSchema($record))
            ->action(function (Lead $record, array $data, Action $action) {
                try {
                    $result = app(LeadStatusService::class)->apply($record, $data['status'], $data);
                } catch (ValidationException $exception) {
                    Notification::make()->title('Nicht gespeichert')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Status gespeichert: '.Adk::statusLabel($data['status']))->success()->send();

                if ($result->suggestRest) {
                    static::notifyRest($record);
                }
            });
    }

    /** @return list<Component|Field> */
    public static function statusSchema(Lead $lead): array
    {
        $follow = fn (Get $get) => config('adk.statuses.'.$get('status').'.follow_up');
        $statusOptions = collect(Adk::statuses())
            ->except('new')
            ->mapWithKeys(fn (array $status, string $key) => [$key => ($status['key'] !== null ? "{$status['key']} · " : '').$status['label']])
            ->all();

        return [
            Select::make('status')
                ->label('Status')
                ->options($statusOptions)
                ->required()
                ->live(),
            DatePicker::make('next_action_at')
                ->label('Wiedervorlage am')
                ->minDate(today())
                ->visible(fn (Get $get) => $follow($get) === 'required')
                ->required(fn (Get $get) => $follow($get) === 'required'),
            Grid::make(3)
                ->visible(fn (Get $get) => $follow($get) === 'appointment')
                ->schema([
                    DatePicker::make('appointment.date')->label('Termin am')->minDate(today())->required(),
                    TimePicker::make('appointment.time')->label('Uhrzeit')->seconds(false)->required(),
                    Select::make('appointment.type')->label('Art')->options(config('adk.appointment_types'))->required(),
                    Select::make('appointment.user_id')
                        ->label('zuständig')
                        ->options(fn () => User::where('is_blocked', false)->orderBy('name')->pluck('name', 'id'))
                        ->default($lead->assigned_to ?? auth()->id())
                        ->columnSpanFull(),
                ]),
            Select::make('close_reason')
                ->label('Grund')
                ->options(config('adk.wrong_data_reasons'))
                ->visible(fn (Get $get) => (bool) config('adk.statuses.'.$get('status').'.requires_reason'))
                ->required(fn (Get $get) => (bool) config('adk.statuses.'.$get('status').'.requires_reason')),
            Grid::make(3)
                ->visible(fn (Get $get) => $get('status') === 'documents_sent' && $lead->contact_id === null)
                ->schema([
                    TextInput::make('contact.first_name')->label('Vorname Empfänger'),
                    TextInput::make('contact.last_name')->label('Nachname Empfänger')->required(),
                    TextInput::make('contact.email')->label('E-Mail Empfänger')->email(),
                ]),
            Checkbox::make('confirmed')
                ->label('Die Person hat der Ansprache widersprochen. Telefon, E-Mail und Firma kommen dauerhaft auf die Sperrliste.')
                ->visible(fn (Get $get) => (bool) config('adk.statuses.'.$get('status').'.blocklist'))
                ->accepted(fn (Get $get) => (bool) config('adk.statuses.'.$get('status').'.blocklist')),
            Textarea::make('note')
                ->label('Notiz')
                ->rows(3)
                ->helperText('Keine medizinischen Angaben in Notizen.'),
        ];
    }

    public static function crossSelling(): Action
    {
        return Action::make('crossSelling')
            ->label(fn (Lead $record) => $record->cross_selling ? 'Cross-Selling entfernen' : 'Cross-Selling JB Design')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->visible(fn () => Gate::allows('leads.edit'))
            ->schema(fn (Lead $record) => $record->cross_selling ? [
                Textarea::make('note')->label('Notiz')->rows(2),
            ] : [
                DatePicker::make('follow_up_at')->label('Wiedervorlage Cross-Selling')->minDate(today())->required(),
                Textarea::make('note')->label('Notiz')->rows(2),
            ])
            ->action(function (Lead $record, array $data) {
                app(LeadStatusService::class)->toggleCrossSelling($record, $data['follow_up_at'] ?? null, $data['note'] ?? null);
                Notification::make()->title($record->cross_selling ? 'Cross-Selling gesetzt' : 'Cross-Selling entfernt')->success()->send();
            });
    }

    public static function addActivity(): Action
    {
        return Action::make('addActivity')
            ->label('Aktivität erfassen')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->visible(fn () => Gate::allows('leads.edit'))
            ->schema([
                Select::make('type')
                    ->label('Art')
                    ->options(collect(Activity::TYPES)->only(['note', 'call', 'email'])->all())
                    ->default('note')
                    ->required(),
                Textarea::make('body')
                    ->label('Text')
                    ->rows(4)
                    ->required()
                    ->helperText('Keine medizinischen Angaben in Notizen.'),
            ])
            ->action(function (Lead $record, array $data) {
                Activity::create([
                    'lead_id' => $record->id,
                    'type' => $data['type'],
                    'body' => $data['body'],
                ]);

                Notification::make()->title('Aktivität gespeichert')->success()->send();
            });
    }

    public static function notifyRest(Lead $lead): void
    {
        Notification::make()
            ->title("{$lead->call_attempts} Versuche ohne Erfolg")
            ->body('Vorgang ruhen lassen? Die Wiedervorlage wird um '.config('adk.not_reached_rest_months').' Monate verschoben.')
            ->warning()
            ->persistent()
            ->actions([
                Action::make('rest')
                    ->label('Ruhen lassen')
                    ->button()
                    ->dispatch('rest-lead', ['leadId' => $lead->id])
                    ->close(),
                Action::make('keep')->label('Nein')->color('gray')->close(),
            ])
            ->send();
    }
}
