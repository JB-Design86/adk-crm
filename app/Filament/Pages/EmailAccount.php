<?php

namespace App\Filament\Pages;

use App\Models\MailConnection;
use App\Services\Microsoft\MicrosoftClient;
use App\Support\Hilfe;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $plain = trim(str_replace(["
", ""], "
", (string) $connection?->signature));

        $this->form->fill([
            'signature_html' => $connection?->signature_html ?? ($plain !== '' ? '<p>'.nl2br(e($plain), false).'</p>' : null),
            'signature_logo_path' => $connection?->signature_logo_path,
            'signature_logo_width' => $connection?->signature_logo_width ?? 200,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                RichEditor::make('signature_html')
                    ->label('Signatur')
                    ->toolbarButtons([['bold', 'italic', 'underline', 'link'], ['undo', 'redo']])
                    ->helperText('Tipp: Ihre Signatur in Outlook markieren, kopieren und hier einfügen. Enter beginnt einen neuen Absatz, Umschalt + Enter eine neue Zeile.'),
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
        $html = filled(trim(strip_tags((string) ($data['signature_html'] ?? '')))) ? Str::sanitizeHtml((string) $data['signature_html']) : null;

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
