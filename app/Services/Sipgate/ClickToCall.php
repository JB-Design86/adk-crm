<?php

namespace App\Services\Sipgate;

use App\Models\Lead;
use App\Models\User;
use RuntimeException;

/**
 * Anruf per Klick über sipgate: Zuerst klingelt das Gerät der angemeldeten Person,
 * nach dem Abheben wählt sipgate die Nummer des Vorgangs. Sperrliste und
 * Einwilligung werden vorher geprüft, genau wie in der Anrufliste.
 */
class ClickToCall
{
    public function __construct(private SipgateClient $client) {}

    /** Knopf „Über sipgate anrufen“ anzeigen? */
    public static function availableFor(?User $user): bool
    {
        return $user !== null && SipgateClient::isConfigured() && $user->sipgateConnection()->exists();
    }

    /** @throws RuntimeException mit einer Meldung für die Oberfläche */
    public function start(User $user, Lead $lead): void
    {
        if (! SipgateClient::isConfigured()) {
            throw new RuntimeException('sipgate ist noch nicht eingerichtet.');
        }

        $connection = $user->sipgateConnection;

        if ($connection === null) {
            throw new RuntimeException('Bitte verbinden Sie zuerst unter „Telefonie“ Ihr sipgate-Konto.');
        }

        if ($reason = $lead->callBlockReason()) {
            throw new RuntimeException($reason);
        }

        $this->client->call($connection, $lead->phoneE164());

        activity('sipgate')->causedBy($user)->performedOn($lead)->event('call_started')
            ->withProperties(['device' => $connection->device_alias])
            ->log('Anruf über sipgate gestartet');
    }
}
