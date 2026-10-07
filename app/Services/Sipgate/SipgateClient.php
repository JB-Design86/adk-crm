<?php

namespace App\Services\Sipgate;

use App\Models\SipgateConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Anbindung an die sipgate-REST-API (https://api.sipgate.com/v2) per OAuth2.
 * Rechte (Scopes): Anrufe starten, Anrufliste lesen, Geräte lesen.
 */
class SipgateClient
{
    public static function isConfigured(): bool
    {
        return filled(config('services.sipgate.client_id')) && filled(config('services.sipgate.client_secret'));
    }

    public function authorizeUrl(string $state): string
    {
        return $this->realmUrl('auth').'?'.http_build_query([
            'client_id' => config('services.sipgate.client_id'),
            'redirect_uri' => config('services.sipgate.redirect'),
            'response_type' => 'code',
            'scope' => config('services.sipgate.scopes'),
            'state' => $state,
        ]);
    }

    /** Code aus dem Rückruf gegen Tokens tauschen und die Verbindung speichern. */
    public function connect(User $user, string $code): SipgateConnection
    {
        $tokens = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.sipgate.redirect'),
        ]);

        $userinfo = Http::withToken($tokens['access_token'])->acceptJson()
            ->get($this->api('/authorization/userinfo'))
            ->throw()
            ->json();

        return SipgateConnection::updateOrCreate(
            ['user_id' => $user->id],
            [...$this->tokenAttributes($tokens), 'sipgate_user_id' => $userinfo['sub']],
        );
    }

    /** @return list<array{id: string, alias: string, type: string, online: bool}> */
    public function devices(SipgateConnection $connection): array
    {
        $request = $this->request($connection);
        // Neo: ausdrücklich alle Gerätearten, damit auch die sipgate-App erscheint.
        $query = ($this->tokenClaims($connection)['featureScope'] ?? null) === 'NEO_PBX' ? ['type' => 'all'] : [];
        $response = $request->get($this->api('/'.rawurlencode($connection->sipgate_user_id).'/devices'), $query)->throw();

        return array_map(fn (array $device) => [
            'id' => $device['id'],
            'alias' => $device['alias'] ?? $device['id'],
            'type' => $device['type'] ?? '',
            'online' => (bool) ($device['online'] ?? false),
        ], $response->json('items') ?? []);
    }

    /**
     * Anruf per Klick: Zuerst klingelt das gewählte Gerät, danach wird die Nummer angerufen.
     * Klassische sipgate-Anlagen nutzen /sessions/calls, die neue Anlage (Neo) /calls.
     *
     * @return string sessionId (klassisch) bzw. callId (Neo) von sipgate
     */
    public function call(SipgateConnection $connection, string $e164): string
    {
        if (blank($connection->device_id)) {
            throw new RuntimeException('Bitte wählen Sie zuerst unter „Telefonie“ das Gerät, das klingeln soll.');
        }

        $request = $this->request($connection);
        $claims = $this->tokenClaims($connection);

        if (($claims['featureScope'] ?? null) === 'NEO_PBX') {
            return $this->callNeo($request, $connection, $e164, $claims);
        }

        $response = $request->post($this->api('/sessions/calls'), [
            'deviceId' => $connection->device_id,
            'caller' => $connection->device_id,
            'callee' => $e164,
        ]);

        if ($response->status() === 403) {
            throw new RuntimeException('sipgate erlaubt mit dem gewählten Gerät keinen Anruf (403). Bitte wählen Sie unter „Telefonie“ ein anderes Gerät oder verbinden Sie sipgate neu.');
        }

        if ($response->failed()) {
            throw $this->rejected($response, 'klassisch');
        }

        return (string) $response->json('sessionId');
    }

    /** Ablehnung mit Weg und Begründung von sipgate, damit sich der Fehler ohne Serverprotokoll eingrenzen lässt. */
    private function rejected(Response $response, string $route): RuntimeException
    {
        $reason = $response->json('message') ?? $response->json('error') ?? trim(strip_tags($response->body()));

        return new RuntimeException('sipgate hat den Anruf abgelehnt ('.$response->status().', Anlage '.$route.'). '.Str::limit((string) (is_scalar($reason) ? $reason : json_encode($reason)), 300));
    }

    /** @param  array<string, mixed>  $claims */
    private function callNeo(PendingRequest $request, SipgateConnection $connection, string $e164, array $claims): string
    {
        if (! in_array('rtcm:write', explode(' ', (string) ($claims['scope'] ?? '')), true)) {
            throw new RuntimeException('Ihr sipgate-Konto läuft auf der neuen sipgate-Anlage. Bitte trennen Sie sipgate unter „Telefonie“ und verbinden Sie es neu, damit das CRM dort Anrufe starten darf.');
        }

        // Erst mit +, das ist eindeutig. Lehnt sipgate das Format ab (400, es wurde nichts gewählt), ohne + wie im Beispiel der API.
        foreach ([$e164, ltrim($e164, '+')] as $target) {
            $response = $request->post($this->api('/calls'), [
                'deviceId' => $connection->device_id,
                'targetNumber' => $target,
            ]);

            if ($response->status() !== 400) {
                break;
            }
        }

        if ($response->failed()) {
            throw $this->rejected($response, 'Neo');
        }

        return (string) ($response->json('callId') ?? $response->json('sessionId') ?? '');
    }

    /**
     * Angaben im Zugriffstoken (JWT) von sipgate, z. B. featureScope (CLASSIC oder NEO_PBX) und scope.
     * Nur zum Auswählen des Wegs, die Echtheit prüft sipgate bei jedem Aufruf selbst.
     *
     * @return array<string, mixed>
     */
    private function tokenClaims(SipgateConnection $connection): array
    {
        $payload = explode('.', (string) $connection->access_token)[1] ?? '';
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($claims) ? $claims : [];
    }

    /**
     * Anrufe aus der sipgate-Anrufliste seit $from.
     *
     * @return list<array<string, mixed>>
     */
    public function callHistory(SipgateConnection $connection, CarbonImmutable $from, int $limit = 200): array
    {
        $response = $this->request($connection)->get($this->api('/history'), [
            'types' => 'CALL',
            'from' => $from->utc()->format('Y-m-d\TH:i:s\Z'),
            'limit' => $limit,
        ])->throw();

        return $response->json('items') ?? [];
    }

    /** Anfrage mit gültigem Token; läuft das Token ab, wird es vorher erneuert. */
    private function request(SipgateConnection $connection): PendingRequest
    {
        if ($connection->isExpired()) {
            $this->refresh($connection);
        }

        return Http::withToken($connection->access_token)->acceptJson()->asJson()->timeout(15);
    }

    public function refresh(SipgateConnection $connection): void
    {
        $tokens = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
        ]);

        $connection->update($this->tokenAttributes($tokens));
    }

    /** @return array<string, mixed> */
    private function tokenRequest(array $params): array
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post($this->realmUrl('token'), [
            ...$params,
            'client_id' => config('services.sipgate.client_id'),
            'client_secret' => config('services.sipgate.client_secret'),
        ]);

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new RuntimeException('Anmeldung bei sipgate fehlgeschlagen ('.$response->status().'). Bitte verbinden Sie sipgate erneut.');
        }

        return $response->json();
    }

    /** @return array<string, mixed> */
    private function tokenAttributes(array $tokens): array
    {
        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 300)),
            'refresh_expires_at' => isset($tokens['refresh_expires_in']) && $tokens['refresh_expires_in'] > 0
                ? now()->addSeconds((int) $tokens['refresh_expires_in'])
                : null,
        ];
    }

    private function realmUrl(string $endpoint): string
    {
        return config('services.sipgate.login_url').config('services.sipgate.realm').'/protocol/openid-connect/'.$endpoint;
    }

    private function api(string $path): string
    {
        return config('services.sipgate.api_url').$path;
    }
}
