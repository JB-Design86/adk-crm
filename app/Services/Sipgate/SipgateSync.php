<?php

namespace App\Services\Sipgate;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\SipgateConnection;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Übernimmt Anrufe aus der sipgate-Anrufliste als Aktivität „Telefonat (sipgate)“
 * an den Vorgang mit der passenden Telefonnummer. Anrufe mit unbekannten Nummern
 * werden nicht übernommen. Jeder Anruf nur einmal (external_id).
 */
class SipgateSync
{
    public const STATUS = [
        'PICKUP' => 'angenommen',
        'NOPICKUP' => 'nicht angenommen',
        'BUSY' => 'besetzt',
        'FORWARD' => 'weitergeleitet',
    ];

    public const DIRECTIONS = [
        'OUTGOING' => 'Ausgehender Anruf',
        'MISSED_OUTGOING' => 'Ausgehender Anruf',
        'INCOMING' => 'Eingehender Anruf',
        'MISSED_INCOMING' => 'Verpasster Anruf',
    ];

    public function __construct(private SipgateClient $client) {}

    /** @return int Anzahl neu übernommener Anrufe */
    public function sync(SipgateConnection $connection): int
    {
        $from = CarbonImmutable::instance($connection->last_synced_at ?? now()->subDay())->subMinutes(15);
        $started = now();
        $created = 0;

        foreach ($this->client->callHistory($connection, $from) as $entry) {
            if (($entry['type'] ?? 'CALL') !== 'CALL' || blank($entry['id'] ?? null)) {
                continue;
            }

            $externalId = 'sipgate:'.$entry['id'];

            if (Activity::where('external_id', $externalId)->exists()) {
                continue;
            }

            $direction = $entry['direction'] ?? 'OUTGOING';
            $outgoing = str_contains($direction, 'OUTGOING');
            $number = self::number($outgoing ? ($entry['target'] ?? null) : ($entry['source'] ?? null));

            if ($number === null || ! ($lead = $this->findLead($number))) {
                continue;
            }

            $status = $entry['status'] ?? null;
            $duration = isset($entry['duration']) ? (int) $entry['duration'] : null;

            Activity::create([
                'lead_id' => $lead->id,
                'user_id' => $connection->user_id,
                'type' => 'phone_log',
                'outcome' => $status,
                'external_id' => $externalId,
                'duration_seconds' => $duration,
                // sipgate liefert UTC; gespeichert wird in der Zeitzone der Anwendung.
                'occurred_at' => CarbonImmutable::parse($entry['created'] ?? 'now')->setTimezone(config('app.timezone')),
                'body' => implode(', ', array_filter([
                    self::DIRECTIONS[$direction] ?? 'Anruf',
                    self::STATUS[$status] ?? null,
                    $duration ? self::formatDuration($duration) : null,
                ])),
            ]);

            $created++;
        }

        $connection->update(['last_synced_at' => $started]);

        return $created;
    }

    /** Offene Vorgänge zuerst, sonst der zuletzt kontaktierte. */
    private function findLead(string $e164): ?Lead
    {
        return Lead::query()
            ->where(fn (Builder $q) => $q
                ->whereHas('contact', fn (Builder $c) => $c->where('phone_e164', $e164))
                ->orWhereHas('organization', fn (Builder $o) => $o->where('phone_e164', $e164)))
            ->orderByRaw('closed_at IS NOT NULL')
            ->orderByDesc('last_contact_at')
            ->first();
    }

    /**
     * sipgate liefert Nummern international, meist mit „+“ („+4961311234567“), teils ohne.
     * Interne Kennungen („e0“) und unterdrückte Nummern („anonymous“) ergeben null.
     */
    public static function number(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if (preg_match('/^[1-9]\d{9,14}$/', $raw)) {
            $raw = '+'.$raw;
        }

        return preg_match('/^\+?[\d\s\/()-]+$/', $raw) ? Phone::normalize($raw) : null;
    }

    public static function formatDuration(int $seconds): string
    {
        return sprintf('%d:%02d Min.', intdiv($seconds, 60), $seconds % 60);
    }
}
