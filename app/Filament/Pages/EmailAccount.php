<?php

namespace App\Filament\Pages;

use App\Models\MailConnection;
use App\Services\Microsoft\MicrosoftClient;
use App\Support\Hilfe;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
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

    public string $signature = '';

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
        $this->signature = (string) $this->connection()?->signature;
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
        $this->validate(['signature' => ['nullable', 'string', 'max:2000']], attributes: ['signature' => 'Signatur']);

        if (! $this->connection()) {
            return;
        }

        $this->connection()->update(['signature' => filled(trim($this->signature)) ? trim($this->signature) : null]);
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
                    $this->connection()->delete();
                    unset($this->connection);
                    $this->signature = '';

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
