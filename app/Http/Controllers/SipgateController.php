<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Telephony;
use App\Services\Sipgate\SipgateClient;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Verbindung mit sipgate per OAuth2: Weiterleitung zur sipgate-Anmeldung und Rückruf.
 */
class SipgateController extends Controller
{
    public function connect(Request $request): RedirectResponse
    {
        abort_unless(SipgateClient::isConfigured(), 404);
        abort_unless($request->user()->hasTwoFactor(), 403);

        $state = Str::random(40);
        $request->session()->put('sipgate_oauth_state', $state);

        return redirect()->away(app(SipgateClient::class)->authorizeUrl($state));
    }

    public function callback(Request $request, SipgateClient $client): RedirectResponse
    {
        abort_unless(SipgateClient::isConfigured(), 404);
        abort_unless($request->user()->hasTwoFactor(), 403);

        $expected = $request->session()->pull('sipgate_oauth_state');

        if (blank($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return $this->back('Die Verbindung mit sipgate konnte nicht bestätigt werden. Bitte versuchen Sie es erneut.');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->back('sipgate hat die Verbindung nicht freigegeben.');
        }

        try {
            $connection = $client->connect($request->user(), (string) $request->query('code'));
        } catch (Throwable $exception) {
            report($exception);

            return $this->back('Die Verbindung mit sipgate ist fehlgeschlagen. Bitte prüfen Sie Client-ID, Secret und Weiterleitungsadresse.');
        }

        activity('sipgate')->causedBy($request->user())->event('connected')
            ->withProperties(['sipgate_user_id' => $connection->sipgate_user_id])
            ->log('sipgate verbunden');

        Notification::make()->title('sipgate ist verbunden')->body('Bitte wählen Sie jetzt das Gerät, das bei „Anrufen“ klingeln soll.')->success()->send();

        return redirect()->to(Telephony::getUrl());
    }

    private function back(string $message): RedirectResponse
    {
        Notification::make()->title($message)->danger()->send();

        return redirect()->to(Telephony::getUrl());
    }
}
