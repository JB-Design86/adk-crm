<?php

namespace App\Http\Controllers;

use App\Filament\Pages\EmailAccount;
use App\Services\Microsoft\MicrosoftClient;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Verbindung mit dem eigenen Microsoft-365-Postfach per OAuth2: Weiterleitung zur Anmeldung bei Microsoft und Rückruf.
 */
class MicrosoftController extends Controller
{
    public function connect(Request $request): RedirectResponse
    {
        abort_unless(MicrosoftClient::isConfigured(), 404);
        abort_unless($request->user()->hasTwoFactor(), 403);

        $state = Str::random(40);
        $request->session()->put('microsoft_oauth_state', $state);

        return redirect()->away(app(MicrosoftClient::class)->authorizeUrl($state));
    }

    public function callback(Request $request, MicrosoftClient $client): RedirectResponse
    {
        abort_unless(MicrosoftClient::isConfigured(), 404);
        abort_unless($request->user()->hasTwoFactor(), 403);

        $expected = $request->session()->pull('microsoft_oauth_state');

        if (blank($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return $this->back('Die Verbindung mit Microsoft 365 konnte nicht bestätigt werden. Bitte versuchen Sie es erneut.');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            // z. B. access_denied (abgebrochen) oder fehlende Administratorzustimmung (AADSTS65001)
            return $this->back('Microsoft hat die Verbindung nicht freigegeben.', Str::limit(Str::before((string) $request->query('error_description'), "\r\n"), 200));
        }

        try {
            $connection = $client->connect($request->user(), (string) $request->query('code'));
        } catch (RuntimeException $exception) {
            report($exception);

            return $this->back('Die Verbindung mit Microsoft 365 ist fehlgeschlagen.', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->back('Die Verbindung mit Microsoft 365 ist fehlgeschlagen. Bitte prüfen Sie Client-ID, Secret, Mandanten-ID und Weiterleitungsadresse.');
        }

        activity('microsoft')->causedBy($request->user())->event('connected')
            ->withProperties(['mailbox' => $connection->mailbox])
            ->log('Microsoft 365 verbunden');

        Notification::make()->title('Ihr Postfach ist verbunden')->body('E-Mails aus dem CRM gehen ab jetzt von '.$connection->mailbox.'.')->success()->send();

        return redirect()->to(EmailAccount::getUrl());
    }

    private function back(string $message, ?string $details = null): RedirectResponse
    {
        Notification::make()->title($message)->body(filled($details) ? $details : null)->danger()->send();

        return redirect()->to(EmailAccount::getUrl());
    }
}
