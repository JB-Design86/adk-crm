<?php

namespace App\Filament\Pages;

use App\Models\MailConnection;
use App\Services\Microsoft\MicrosoftClient;
use App\Support\Hilfe;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * E-Mail-Konto: eigenes Microsoft-365-Postfach verbinden und Signatur pflegen.
 * Jede Person verbindet nur ihr eigenes Postfach, gesendet wird nur in ihrem Namen.
 */
class EmailAccount extends Page
{
    protected string $view = 'filament.pages.email-account';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Akquise';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'E-Mail-Konto';

    protected static ?string $slug = 'e-mail-konto';

    /** @var array<string, mixed>|null Formular Signatur */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::allows('leads.edit');
    }

    /** Im Menü erst, wenn die App-Registrierung in der .env eingetragen ist. */
    public static function shouldRegisterNavigation(): bool
    {
        return MicrosoftClient::isConfigured();
    }

    public function mount(): void
    {
        $this->fillSignatureForm();
    }

    /** Bisherige Text-Signatur wird beim ersten Öffnen als Absätze in den Editor übernommen. */
    private function fillSignatureForm(): void
    {
        $connection = $this->connection();
        $plain = trim(str_replace(["\r\n", "\r"], "\n", (string) $connection?->signature));

        $html = $connection?->signature_html ?? ($plain !== '' ? '<p>'.nl2br(e($plain), false).'</p>' : null);

        $this->form->fill([
            'signature_html' => $html,
            // Eine Tabelle kann der Editor nicht darstellen und würde sie beim Speichern verlieren: dann im HTML-Code öffnen.
            'signature_as_code' => str_contains((string) $html, '<table'),
            'signature_code' => $html,
            'signature_logo_path' => $connection?->signature_logo_path,
            'signature_logo_width' => $connection?->signature_logo_width ?? 200,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('signature_as_code')
                    ->label('Als HTML-Code bearbeiten')
                    ->helperText('Für Fortgeschrittene, z. B. für Telefonnummern, die bündig untereinander stehen (Tabelle).')
                    ->live(),
                RichEditor::make('signature_html')
                    ->label('Signatur')
                    ->toolbarButtons([['bold', 'italic', 'underline', 'link'], ['undo', 'redo']])
                    ->visible(fn (Get $get) => ! $get('signature_as_code'))
                    ->helperText('Tipp: Ihre Signatur in Outlook markieren, kopieren und hier einfügen. Enter beginnt einen neuen Absatz, Umschalt + Enter eine neue Zeile.'),
                Textarea::make('signature_code')
                    ->label('Signatur als HTML-Code')
                    ->rows(14)
                    ->extraInputAttributes(['class' => 'font-mono text-sm', 'spellcheck' => 'false'])
                    ->visible(fn (Get $get) => (bool) $get('signature_as_code'))
                    ->helperText('Erlaubt sind übliche Auszeichnungen wie <p>, <strong>, <a>, <br> und Tabellen mit style-Angaben. Skripte und eingebettete Bilder entfernt das CRM beim Speichern. Das Logo kommt aus dem Feld darunter.'),
                FileUpload::make('signature_logo_path')
                    ->label('Logo (optional)')
                    ->image()
                    ->disk(MailConnection::LOGO_DISK)
                    ->directory(MailConnection::LOGO_DIRECTORY)
                    ->visibility('private')
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/gif'])
                    ->maxSize(300)
                    ->helperText('PNG oder JPG, höchstens 300 KB. Steht unter der Signatur und wird als Bild in die E-Mail eingebettet.'),
                TextInput::make('signature_logo_width')
                    ->label('Breite des Logos')
                    ->numeric()
                    ->minValue(60)
                    ->maxValue(600)
                    ->suffix('Pixel'),
            ])
            ->statePath('data');
    }

    #[Computed]
    public function configured(): bool
    {
        return MicrosoftClient::isConfigured();
    }

    #[Computed]
    public function connection(): ?MailConnection
    {
        return auth()->user()->mailConnection;
    }

    public function saveSignature(): void
    {
        $connection = $this->connection();

        if (! $connection) {
            return;
        }

        $data = $this->form->getState();
        $source = (string) (! empty($data['signature_as_code']) ? ($data['signature_code'] ?? '') : ($data['signature_html'] ?? ''));
        // Bilder kommen nur über das Logo-Feld (eingebettet per cid), eingefügte Bilder würden die Mail aufblähen.
        $html = filled(trim(strip_tags($source))) ? MailConnection::cleanSignature($source) : null;

        if (mb_strlen((string) $html) > 20000) {
            Notification::make()->title('Die Signatur ist zu lang')->body('Bitte kürzen Sie sie, z. B. ohne eingefügte Bilder.')->danger()->send();

            return;
        }

        $logo = $data['signature_logo_path'] ?? null;

        // Ersetztes oder entferntes Logo löschen.
        if ($connection->signature_logo_path && $connection->signature_logo_path !== $logo) {
            Storage::disk(MailConnection::LOGO_DISK)->delete($connection->signature_logo_path);
        }

        $connection->update([
            'signature_html' => $html,
            // Die HTML-Signatur ersetzt die alte Text-Signatur.
            'signature' => null,
            'signature_logo_path' => $logo,
            'signature_logo_width' => filled($data['signature_logo_width'] ?? null) ? (int) $data['signature_logo_width'] : null,
        ]);
        unset($this->connection);
        $this->fillSignatureForm();

        Notification::make()->title('Signatur gespeichert')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label('Neu verbinden')
                ->icon(Heroicon::OutlinedLink)
                ->color('gray')
                ->visible(fn () => $this->configured() && $this->connection())
                ->url(fn () => route('filament.crm.microsoft.connect')),
            Action::make('disconnect')
                ->label('Verbindung trennen')
                ->icon(Heroicon::OutlinedLinkSlash)
                ->color('danger')
                ->visible(fn () => $this->connection() !== null)
                ->requiresConfirmation()
                ->modalHeading('Postfach trennen?')
                ->modalDescription('Das CRM kann danach keine E-Mails mehr aus Ihrem Postfach senden, und Ihre Signatur im CRM wird gelöscht. Gesendete E-Mails bleiben in Outlook und im Verlauf der Vorgänge.')
                ->modalSubmitActionLabel('Trennen')
                ->action(function () {
                    if ($this->connection()->signature_logo_path) {
                        Storage::disk(MailConnection::LOGO_DISK)->delete($this->connection()->signature_logo_path);
                    }

                    $this->connection()->delete();
                    unset($this->connection);
                    $this->fillSignatureForm();

                    activity('microsoft')->causedBy(auth()->user())->event('disconnected')->log('Microsoft 365 getrennt');
                    Notification::make()->title('Ihr Postfach ist getrennt')->success()->send();
                }),
        ];
    }

    public function getSubheading(): ?string
    {
        return Hilfe::seite('e_mail_konto');
    }
}
