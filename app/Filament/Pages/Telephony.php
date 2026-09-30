<?php

namespace App\Filament\Pages;

use App\Models\SipgateConnection;
use App\Services\Sipgate\SipgateClient;
use App\Services\Sipgate\SipgateSync;
use App\Support\Hilfe;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Throwable;
use UnitEnum;

/**
 * Telefonie: eigenes sipgate-Konto verbinden, Gerät für „Anrufen“ wählen,
 * Anrufliste abgleichen. Jede Person verbindet nur ihr eigenes Konto.
 */
class Telephony extends Page
{
    protected string $view = 'filament.pages.telephony';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Akquise';

    protected static ?int $navigationSort = 8;

    protected static ?string $title = 'Telefonie';

    protected static ?string $slug = 'telefonie';

    public ?string $deviceId = null;

    public ?string $devicesError = null;

    public static function canAccess(): bool
    {
        return Gate::allows('call_list');
    }

    /** Im Menü erst, wenn sipgate in der .env eingerichtet ist. */
    public static function shouldRegisterNavigation(): bool
    {
        return SipgateClient::isConfigured();
    }

    public function mount(): void
    {
        $this->deviceId = $this->connection()?->device_id;
    }

    #[Computed]
    public function configured(): bool
    {
        return SipgateClient::isConfigured();
    }

    #[Computed]
    public function connection(): ?SipgateConnection
    {
        return auth()->user()->sipgateConnection;
    }

    /** @return list<array{id: string, alias: string, type: string, online: bool}> */
    #[Computed]
    public function devices(): array
    {
        if (! $this->configured() || ! $this->connection()) {
            return [];
        }

        try {
            $this->devicesError = null;

            return app(SipgateClient::class)->devices($this->connection());
        } catch (Throwable $exception) {
            report($exception);
            $this->devicesError = 'Die Geräte konnten nicht von sipgate geladen werden. Bitte verbinden Sie sipgate erneut.';

            return [];
        }
    }

    public function saveDevice(): void
    {
        $device = collect($this->devices())->firstWhere('id', $this->deviceId);

        if (! $device || ! $this->connection()) {
            Notification::make()->title('Bitte wählen Sie ein Gerät aus der Liste.')->danger()->send();

            return;
        }

        $this->connection()->update(['device_id' => $device['id'], 'device_alias' => $device['alias']]);
        unset($this->connection);

        Notification::make()->title('Gespeichert: Bei „Anrufen“ klingelt '.$device['alias'].'.')->success()->send();
    }

    public static function deviceType(string $type): string
    {
        return match ($type) {
            'REGISTER' => 'Telefon oder Softphone',
            'MOBILE' => 'Mobiltelefon',
            'EXTERNAL' => 'externe Rufnummer',
            default => $type,
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label('Neu verbinden')
                ->icon(Heroicon::OutlinedLink)
                ->color('gray')
                ->visible(fn () => $this->configured() && $this->connection())
                ->url(fn () => route('filament.crm.sipgate.connect')),
            Action::make('sync')
                ->label('Jetzt abgleichen')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn () => $this->configured() && $this->connection())
                ->action(function () {
                    try {
                        $count = app(SipgateSync::class)->sync($this->connection());
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()->title('Abgleich fehlgeschlagen')->body('Bitte verbinden Sie sipgate erneut.')->danger()->send();

                        return;
                    }

                    unset($this->connection);
                    Notification::make()->title($count === 1 ? '1 Anruf übernommen' : "{$count} Anrufe übernommen")->success()->send();
                }),
            Action::make('disconnect')
                ->label('Verbindung trennen')
                ->icon(Heroicon::OutlinedLinkSlash)
                ->color('danger')
                ->visible(fn () => $this->connection() !== null)
                ->requiresConfirmation()
                ->modalHeading('sipgate-Verbindung trennen?')
                ->modalDescription('Das CRM kann danach keine Anrufe mehr starten und übernimmt keine Anrufe mehr aus Ihrer sipgate-Anrufliste. Bereits übernommene Anrufe bleiben erhalten.')
                ->modalSubmitActionLabel('Trennen')
                ->action(function () {
                    $this->connection()->delete();
                    unset($this->connection, $this->devices);
                    $this->deviceId = null;

                    activity('sipgate')->causedBy(auth()->user())->event('disconnected')->log('sipgate getrennt');
                    Notification::make()->title('sipgate ist getrennt')->success()->send();
                }),
        ];
    }

    public function getSubheading(): ?string
    {
        return Hilfe::seite('telefonie');
    }
}
