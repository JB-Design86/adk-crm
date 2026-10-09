<?php

namespace App\Filament\Actions;

use App\Filament\Pages\EmailAccount;
use App\Models\Activity;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadStatusService;
use App\Services\Microsoft\GraphMailer;
use App\Services\Microsoft\LeadEmail;
use App\Services\Microsoft\MicrosoftClient;
use App\Services\Sipgate\ClickToCall;
use App\Support\Adk;
use App\Support\Hilfe;
use App\Support\MailHtml;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

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
            ->reject(fn (array $status) => ($status['manual'] ?? true) === false)
            ->mapWithKeys(fn (array $status, string $key) => [$key => ($status['key'] !== null ? "{$status['key']} · " : '').$status['label']])
            ->all();

        return [
            Select::make('status')
                ->label('Status')
                ->options($statusOptions)
                ->required()
                ->helperText(fn (Get $get) => Hilfe::status($get('status')))
                ->live()
                // Wiedervorlage vorschlagen (z. B. in 2 Arbeitstagen), bei Pflichtdatum leer lassen.
                ->afterStateUpdated(fn (Set $set, ?string $state) => $set('next_action_at', LeadStatusService::suggestedDate($state)?->toDateString())),
            Select::make('target_group')
                ->label('Zielgruppe für den Förderweg')
                ->options(collect(Adk::targetGroupOptions())->except(['company_open', 'open'])->all())
                ->default($lead->fundingPathway() ? $lead->target_group : null)
                ->visible(fn (Get $get) => $get('status') === 'handed_over')
                ->required(fn (Get $get) => $get('status') === 'handed_over'),
            Grid::make(2)
                ->visible(fn (Get $get) => LeadStatusService::hasFollowUpDate($get('status')))
                ->schema([
                    DatePicker::make('next_action_at')
                        ->label('Wiedervorlage am')
                        ->minDate(today())
                        ->helperText(fn (Get $get) => $follow($get) === 'working_days'
                            ? 'Vorschlag: in '.config('adk.statuses.'.$get('status').'.days').' Arbeitstagen. Bei Bedarf ändern.'
                            : null)
                        ->required(fn (Get $get) => $follow($get) === 'required'),
                    TimePicker::make('next_action_time')
                        ->label('Uhrzeit (optional)')
                        ->seconds(false)
                        ->helperText('Für einen Rückruf zu einer festen Zeit. Das CRM erinnert Sie zur Uhrzeit.'),
                ]),
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

    /** Anruf per Klick: Zuerst klingelt das eigene Gerät, dann wählt sipgate die Nummer. */
    public static function sipgateCall(): Action
    {
        return Action::make('sipgateCall')
            ->label('Über sipgate anrufen')
            ->icon(Heroicon::OutlinedPhoneArrowUpRight)
            ->color('primary')
            ->visible(fn (Lead $record) => Gate::allows('call_list') && ClickToCall::availableFor(auth()->user()) && $record->phoneE164() !== null)
            ->disabled(fn (Lead $record) => ! $record->isCallable())
            ->tooltip(fn (Lead $record) => $record->callBlockReason() ?? Hilfe::feld('sipgate_call'))
            ->action(function (Lead $record) {
                try {
                    app(ClickToCall::class)->start(auth()->user(), $record);
                } catch (RuntimeException $exception) {
                    Notification::make()->title('Anruf nicht gestartet')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Ihr Telefon klingelt gleich')->body('Nach dem Abheben wählt sipgate '.$record->phoneDisplay().'.')->success()->send();
            });
    }

    /**
     * E-Mail aus dem eigenen Microsoft-365-Postfach. Ohne verbundenes Postfach öffnet der Knopf
     * kein Formular, sondern weist auf „E-Mail-Konto“ hin.
     */
    public static function sendEmail(): Action
    {
        $connected = fn () => LeadEmail::connectedFor(auth()->user());

        return Action::make('sendEmail')
            ->label('E-Mail schreiben')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->visible(fn (?Lead $record) => $record !== null && Gate::allows('leads.edit') && MicrosoftClient::isConfigured() && $record->isOpen())
            ->disabled(fn (?Lead $record) => $record?->isBlocked() ?? false)
            ->tooltip(fn (?Lead $record) => ($record ? LeadEmail::blockReason($record) : null) ?? Hilfe::feld('send_email'))
            ->modalHidden(fn () => ! $connected())
            ->modalHeading(fn (Lead $record) => 'E-Mail: '.$record->displayName())
            ->modalDescription(fn () => 'Geht von '.auth()->user()->mailConnection?->mailbox.' und liegt danach in Outlook unter „Gesendete Elemente“.')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitActionLabel('Senden')
            ->schema(fn (Lead $record) => $connected() ? static::emailSchema($record) : [])
            ->action(function (Lead $record, array $data, Action $action, HasActions $livewire) use ($connected) {
                if (! $connected()) {
                    Notification::make()
                        ->title('Bitte verbinden Sie zuerst Ihr Postfach')
                        ->body('Unter „E-Mail-Konto“ verbinden Sie einmal Ihr Microsoft-365-Postfach. Danach schreiben Sie hier E-Mails.')
                        ->warning()
                        ->actions([Action::make('emailAccount')->label('Zum E-Mail-Konto')->button()->url(EmailAccount::getUrl())])
                        ->send();

                    return;
                }

                try {
                    app(LeadEmail::class)->send(auth()->user(), $record, $data);
                } catch (RuntimeException $exception) {
                    // Formular bleibt offen, damit der Text nicht verloren geht. Hochgeladene Anhänge sind
                    // schon vom Server gelöscht, das Feld wird geleert und sie werden neu gewählt.
                    $uploads = filled($data['email_attachments'] ?? null);

                    if ($uploads) {
                        $livewire->mountedActions[$action->getNestingIndex()]['data']['email_attachments'] = [];
                        $livewire->mountedActions[$action->getNestingIndex()]['data']['email_attachment_names'] = [];
                    }

                    Notification::make()
                        ->title('E-Mail nicht gesendet')
                        ->body($uploads ? Str::finish($exception->getMessage(), '.').' Die hochgeladenen Anhänge sind gelöscht, bitte fügen Sie sie erneut hinzu.' : $exception->getMessage())
                        ->danger()
                        ->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('E-Mail an '.$data['email_to'].' gesendet')->success()->send();
            });
    }

    /** @return list<Component|Field> */
    public static function emailSchema(Lead $lead): array
    {
        $contact = $lead->contact;
        // Dateien aller aktiven Vorlagen, damit z. B. das Kursheft mit jeder E-Mail mitgehen kann.
        $templateFiles = EmailTemplate::active()->ordered()->whereNotNull('attachment_path')->get()
            ->mapWithKeys(fn (EmailTemplate $template) => [$template->id => $template->attachmentName().' (Vorlage „'.$template->name.'“)'])
            ->all();

        return [
            Select::make('email_template_id')
                ->label('Vorlage')
                ->options(fn () => EmailTemplate::active()->ordered()->pluck('name', 'id'))
                ->placeholder('ohne Vorlage')
                ->live()
                ->afterStateUpdated(function (Set $set, ?string $state) use ($lead) {
                    $chosen = filled($state) ? EmailTemplate::active()->find($state) : null;

                    if ($chosen) {
                        $set('email_subject', LeadEmail::render($chosen->subject, $lead, auth()->user()));
                        $set('email_text', LeadEmail::renderHtml($chosen->bodyHtml(), $lead, auth()->user()));
                        $set('email_template_files', $chosen->hasAttachment() ? [$chosen->id] : []);
                    }
                }),
            TextInput::make('email_to')
                ->label('An')
                ->email()
                ->required()
                ->maxLength(255)
                ->default(LeadEmail::defaultRecipient($lead))
                ->rules([fn () => function (string $attribute, mixed $value, Closure $fail) use ($lead) {
                    if ($reason = LeadEmail::blockReason($lead, (string) $value)) {
                        $fail($reason);
                    }
                }]),
            TextInput::make('email_subject')
                ->label('Betreff')
                ->required()
                ->maxLength(255),
            RichEditor::make('email_text')
                ->label('Text')
                ->toolbarButtons(MailHtml::TOOLBAR)
                ->fileAttachments(false)
                ->minHeight('16rem')
                ->required()
                ->helperText('Enter beginnt einen neuen Absatz, Umschalt + Enter eine neue Zeile. Ihre Signatur aus „E-Mail-Konto“ hängt das CRM beim Senden an. Keine medizinischen Angaben.'),
            CheckboxList::make('email_template_files')
                ->label('Dateien aus den Vorlagen')
                ->options($templateFiles)
                ->visible($templateFiles !== []),
            FileUpload::make('email_attachments')
                ->label('Anhänge (optional)')
                ->multiple()
                ->maxFiles(5)
                ->disk(LeadEmail::UPLOAD_DISK)
                ->directory(LeadEmail::UPLOAD_DIRECTORY)
                ->visibility('private')
                ->storeFileNamesIn('email_attachment_names')
                // Nur neu hochgeladene Dateien, keine Pfade aus dem Browser (die Dateien werden nach dem Senden gelöscht).
                ->preventFilePathTampering()
                ->maxSize((int) (GraphMailer::MAX_ATTACHMENT_BYTES / 1024))
                ->acceptedFileTypes(EmailTemplate::ATTACHMENT_TYPES)
                ->helperText('Bis zu 5 Dateien (PDF, JPG, PNG, Word, Excel, ODT). Alle Anhänge zusammen höchstens 3 MB. Die Dateien werden direkt nach dem Senden vom Server gelöscht.'),
            Checkbox::make('consent_confirmed')
                ->label('Die Person hat um diese E-Mail gebeten oder eingewilligt (z. B. im Telefonat)')
                ->accepted()
                ->validationMessages(['accepted' => 'Ohne Bitte oder Einwilligung der Person bitte keine E-Mail senden.'])
                ->helperText('Werbung per E-Mail ist nur mit vorheriger Einwilligung erlaubt, auch gegenüber Betrieben (§ 7 UWG). Gemeint ist z. B. „Schicken Sie mir die Unterlagen“ im Telefonat oder eine Anfrage über die Website.'
                    .($contact?->hasEmailConsent() ? ' Am Kontakt eingetragen: Einwilligung in E-Mails seit '.$contact->email_consent_at->format('d.m.Y').'.' : '')),
            Select::make('status_after')
                ->label('Status danach (optional)')
                ->options(LeadEmail::statusOptions($lead))
                ->placeholder('unverändert lassen')
                ->live()
                ->helperText(fn (Get $get) => match (config('adk.statuses.'.$get('status_after').'.follow_up')) {
                    'working_days' => 'Wiedervorlage automatisch in '.config('adk.statuses.'.$get('status_after').'.days').' Arbeitstagen, außer Sie tragen unten ein Datum ein.',
                    'required' => 'Bitte unten ein Wiedervorlagedatum eintragen.',
                    default => $lead->contact ? null : '„Unterlagen versendet“ braucht eine Ansprechperson am Vorgang, dafür bitte „Status setzen“ verwenden.',
                }),
            Grid::make(2)->schema([
                DatePicker::make('next_action_at')
                    ->label(fn (Get $get) => config('adk.statuses.'.$get('status_after').'.follow_up') === 'required' ? 'Wiedervorlage am' : 'Wiedervorlage am (optional)')
                    ->minDate(today())
                    ->requiredWith('next_action_time')
                    ->required(fn (Get $get) => config('adk.statuses.'.$get('status_after').'.follow_up') === 'required')
                    ->helperText('Leer lassen: Wiedervorlage bleibt '.($lead->nextActionLabel() ?? 'leer').' bzw. folgt dem Status.'),
                TimePicker::make('next_action_time')
                    ->label('Uhrzeit (optional)')
                    ->seconds(false),
            ]),
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

    /** Wiedervorlage verschieben, ohne den Status zu ändern, z. B. nach einem Tippfehler beim Datum. */
    public static function changeFollowUp(): Action
    {
        return Action::make('changeFollowUp')
            ->label('Wiedervorlage ändern')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->visible(fn (Lead $record) => Gate::allows('leads.edit') && $record->isOpen())
            ->fillForm(fn (Lead $record) => [
                'next_action_at' => $record->next_action_at?->toDateString(),
                'next_action_time' => $record->next_action_time,
            ])
            ->schema([
                Grid::make(2)->schema([
                    DatePicker::make('next_action_at')->label('Wiedervorlage am')->minDate(today())->required(),
                    TimePicker::make('next_action_time')->label('Uhrzeit (optional)')->seconds(false),
                ]),
                Textarea::make('note')->label('Notiz (optional)')->rows(2),
            ])
            ->action(function (Lead $record, array $data) {
                $before = $record->nextActionLabel() ?? 'keine';
                $record->next_action_at = $data['next_action_at'];
                $record->next_action_time = $data['next_action_time'] ?? null;
                $record->save();

                Activity::create([
                    'lead_id' => $record->id,
                    'user_id' => auth()->id(),
                    'type' => 'note',
                    'body' => implode("\n", array_filter([
                        'Wiedervorlage geändert: '.$before.' → '.$record->nextActionLabel(),
                        filled($data['note'] ?? null) ? trim($data['note']) : null,
                    ])),
                ]);

                Notification::make()->title('Wiedervorlage: '.$record->nextActionLabel())->success()->send();
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
