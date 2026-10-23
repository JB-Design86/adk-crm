<?php

namespace App\Http\Controllers;

use App\Filament\Pages\EmailAccount;
use App\Services\Microsoft\MicrosoftClient;
use App\Support\Brand;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Verbindung mit dem eigenen Microsoft-365-Postfach per OAuth2: Weiterleitung zur Anmeldung bei Microsoft und Rückruf.
 */
class MicrosoftController extends Controller
{
    /** ?marke=jb verbindet das Postfach für JB IT-Design, ohne Angabe das der ADK. */
    public function connect(Request $request): RedirectResponse
    {
        $brand = (string) $request->query('marke', Brand::default());

        abort_unless(Brand::exists($brand) && $request->user()->canUseBrand($brand), 404);
        abort_unless(MicrosoftClient::isConfigured($brand), 404);
        abort_unless($request->user()->hasTwoFactor(), 403);

        $state = Str::random(40);
        $request->session()->put('microsoft_oauth_state', $state);
        $request->session()->put('microsoft_oauth_brand', $brand);

        return redirect()->away(app(MicrosoftClient::class)->authorizeUrl($state, $brand));
    }

    /**
     * Rückkehr von Microsoft, erster Schritt. Das Sitzungscookie ist SameSite=strict. Hat die Person bei
     * Microsoft etwas eingegeben (Kennwort, Code, Zustimmung), geht die Weiterleitung von
     * login.microsoftonline.com aus, und der Browser schickt das Cookie nicht mit: Das CRM sähe eine
     * abgemeldete Person. Diese Seite braucht deshalb keine Sitzung und leitet innerhalb des CRM
     * weiter. Diese Navigation geht vom CRM selbst aus, also kommt das Cookie mit (callback()).
     */
    public function bounce(Request $request): Response
    {
        abort_unless(MicrosoftClient::isConfigured(), 404);

        $target = route('filament.crm.microsoft.complete', $request->only(['code', 'state', 'error', 'error_description']));

        return response()
            ->view('microsoft.weiter', ['target' => $target])
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function callback(Request $request, MicrosoftClient $client): RedirectResponse
    {
        $brand = (string) $request->session()->pull('microsoft_oauth_brand', Brand::default());

        abort_unless(Brand::exists($brand) && MicrosoftClient::isConfigured($brand), 404);
        abort_unless($request->user()->hasTwoFactor(), 403);

        $expected = $request->session()->pull('microsoft_oauth_state');

        if (blank($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return $this->back($brand, 'Die Verbindung mit Microsoft 365 konnte nicht bestätigt werden. Bitte versuchen Sie es erneut.');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            // z. B. access_denied (abgebrochen) oder fehlende Administratorzustimmung (AADSTS65001)
            return $this->back($brand, 'Microsoft hat die Verbindung nicht freigegeben.', Str::limit(Str::before((string) $request->query('error_description'), "\r\n"), 200));
        }

        try {
            $connection = $client->connect($request->user(), (string) $request->query('code'), $brand);
        } catch (RuntimeException $exception) {
            report($exception);

            return $this->back($brand, 'Die Verbindung mit Microsoft 365 ist fehlgeschlagen.', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->back($brand, 'Die Verbindung mit Microsoft 365 ist fehlgeschlagen. Bitte prüfen Sie Client-ID, Secret, Mandanten-ID und Weiterleitungsadresse.');
        }

        activity('microsoft')->causedBy($request->user())->event('connected')
            ->withProperties(['mailbox' => $connection->mailbox, 'brand' => Brand::label($brand)])
            ->log('Microsoft 365 verbunden');

        Notification::make()->title('Ihr Postfach ist verbunden')->body('E-Mails für '.Brand::label($brand).' gehen ab jetzt von '.$connection->mailbox.'.')->success()->send();

        return redirect()->to(self::accountUrl($brand));
    }

    private function back(string $brand, string $message, ?string $details = null): RedirectResponse
    {
        Notification::make()->title($message)->body(filled($details) ? $details : null)->danger()->send();

        return redirect()->to(self::accountUrl($brand));
    }

    /** Seite „E-Mail-Konto“ der Marke, für die ADK ohne Zusatz. */
    private static function accountUrl(string $brand): string
    {
        return EmailAccount::getUrl($brand === Brand::default() ? [] : ['marke' => $brand]);
    }
}
