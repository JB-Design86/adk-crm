<?php

namespace App\Services\Sipgate;

use App\Models\SipgateConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
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
        $response = $this->request($connection)->get($this->api('/'.rawurlencode($connection->sipgate_user_id).'/devices'))->throw();

        return array_map(fn (array $device) => [
            'id' => $device['id'],
            'alias' => $device['alias'] ?? $device['id'],
            'type' => $device['type'] ?? '',
            'online' => (bool) ($device['online'] ?? false),
        ], $response->json('items') ?? []);
    }

    /**
     * Anruf per Klick: Zuerst klingelt das gewählte Gerät, danach wird die Nummer angerufen.
     *
     * @return string sessionId von sipgate
     */
    public function call(SipgateConnection $connection, string $e164): string
    {
        if (blank($connection->device_id)) {
            throw new RuntimeException('Bitte wählen Sie zuerst unter „Telefonie“ das Gerät, das klingeln soll.');
        }

        $response = $this->request($connection)->post($this->api('/sessions/calls'), [
            'deviceId' => $connection->device_id,
            'caller' => $connection->device_id,
            'callee' => $e164,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('sipgate hat den Anruf abgelehnt ('.$response->status().'). '.($response->json('message') ?? ''));
        }

        return (string) $response->json('sessionId');
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
