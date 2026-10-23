<?php

namespace App\Services\Microsoft;

use App\Models\MailConnection;
use App\Models\User;
use App\Support\Brand;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Anmeldung bei Microsoft 365 (Microsoft Entra, OAuth2 mit Autorisierungscode) und Zugriff auf
 * Microsoft Graph im Namen der angemeldeten Person (delegierte Rechte). Das CRM kann damit nur
 * als diese Person senden, nie aus fremden Postfächern.
 */
class MicrosoftClient
{
    public const RECONNECT = 'Bitte verbinden Sie Ihr Postfach unter „E-Mail-Konto“ neu.';

    /** Client-ID, Secret und Mandant für eine Marke (config services.microsoft.brands), sonst die allgemeinen Werte. */
    public static function credential(string $key, ?string $brand = null): ?string
    {
        $value = $brand !== null ? config("services.microsoft.brands.{$brand}.{$key}") : null;

        return filled($value) ? $value : config("services.microsoft.{$key}");
    }

    public static function isConfigured(?string $brand = null): bool
    {
        return filled(self::credential('client_id', $brand)) && filled(self::credential('client_secret', $brand));
    }

    public function authorizeUrl(string $state, ?string $brand = null): string
    {
        return $this->loginUrl('authorize', $brand).'?'.http_build_query([
            'client_id' => self::credential('client_id', $brand),
            'response_type' => 'code',
            'redirect_uri' => config('services.microsoft.redirect'),
            'response_mode' => 'query',
            'scope' => config('services.microsoft.scopes'),
            'state' => $state,
            // Bei mehreren angemeldeten Konten im Browser ausdrücklich das richtige wählen lassen.
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Code aus dem Rückruf gegen Tokens tauschen, Postfach abfragen und die Verbindung der Marke speichern. */
    public function connect(User $user, string $code, ?string $brand = null): MailConnection
    {
        $brand ??= Brand::default();

        $tokens = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.microsoft.redirect'),
        ], $brand);

        // Ohne Recht zum Senden ist die Verbindung nutzlos, z. B. wenn die Administratorzustimmung fehlt.
        if (isset($tokens['scope']) && ! Str::contains((string) $tokens['scope'], 'mail.send', ignoreCase: true)) {
            throw new RuntimeException('Microsoft hat das Recht zum Senden (Mail.Send) nicht freigegeben. Bitte lassen Sie die Verwaltung die Berechtigungen der App in Microsoft Entra prüfen.');
        }

        $me = Http::withToken($tokens['access_token'])->acceptJson()->timeout(15)
            ->get($this->graph('/me'), ['$select' => 'displayName,mail,userPrincipalName'])
            ->throw()
            ->json();

        // „mail“ ist die Absenderadresse; ohne sie bleibt nur der Anmeldename (meist dieselbe Adresse).
        $mailbox = filled($me['mail'] ?? null) ? $me['mail'] : ($me['userPrincipalName'] ?? null);

        if (blank($mailbox)) {
            throw new RuntimeException('Microsoft hat keine E-Mail-Adresse zu diesem Konto geliefert. Hat das Konto ein Exchange-Postfach?');
        }

        return MailConnection::updateOrCreate(
            ['user_id' => $user->id, 'brand' => $brand],
            [...$this->tokenAttributes($tokens), 'mailbox' => Str::lower($mailbox), 'display_name' => $me['displayName'] ?? null],
        );
    }

    /** Anfrage an Microsoft Graph mit gültigem Token; läuft das Token ab, wird es vorher erneuert. */
    public function request(MailConnection $connection): PendingRequest
    {
        if ($connection->isExpired()) {
            $this->refresh($connection);
        }

        return Http::withToken($connection->access_token)->acceptJson()->asJson()->timeout(30);
    }

    public function refresh(MailConnection $connection): void
    {
        $tokens = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
        ], $connection->brand);

        $connection->update($this->tokenAttributes($tokens, $connection->refresh_token));
    }

    public function graph(string $path): string
    {
        return config('services.microsoft.graph_url').$path;
    }

    /** @return array<string, mixed> */
    private function tokenRequest(array $params, ?string $brand = null): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->post($this->loginUrl('token', $brand), [
                ...$params,
                'client_id' => self::credential('client_id', $brand),
                'client_secret' => self::credential('client_secret', $brand),
                'scope' => config('services.microsoft.scopes'),
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Die Anmeldung bei Microsoft ist gerade nicht erreichbar. Bitte versuchen Sie es in einigen Minuten erneut.');
        }

        if ($response->failed() || blank($response->json('access_token'))) {
            // z. B. invalid_grant: Freigabe widerrufen, Kennwort geändert oder Token älter als 90 Tage.
            $reason = Str::before((string) $response->json('error_description'), "\r\n");

            throw new RuntimeException(trim('Anmeldung bei Microsoft fehlgeschlagen ('.$response->status().'). '.self::RECONNECT.' '.Str::limit($reason, 200)));
        }

        return $response->json();
    }

    /** @return array<string, mixed> */
    private function tokenAttributes(array $tokens, ?string $previousRefreshToken = null): array
    {
        return [
            'access_token' => $tokens['access_token'],
            // Microsoft liefert beim Erneuern meist ein neues Refresh-Token, sonst gilt das bisherige weiter.
            'refresh_token' => $tokens['refresh_token'] ?? $previousRefreshToken ?? throw new RuntimeException('Microsoft hat kein Refresh-Token geliefert (Recht offline_access fehlt).'),
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
        ];
    }

    private function loginUrl(string $endpoint, ?string $brand = null): string
    {
        return config('services.microsoft.login_url').rawurlencode((string) self::credential('tenant', $brand)).'/oauth2/v2.0/'.$endpoint;
    }
}
