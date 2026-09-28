<?php

namespace App\Console\Commands;

use App\Models\SipgateConnection;
use App\Services\Sipgate\SipgateClient;
use App\Services\Sipgate\SipgateSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Übernimmt neue Anrufe aus sipgate für alle verbundenen, nicht gesperrten Konten.
 * Läuft alle 5 Minuten über den Zeitplan (routes/console.php).
 */
class SipgateSyncCommand extends Command
{
    protected $signature = 'adk:sipgate-abgleich';

    protected $description = 'Übernimmt neue Anrufe aus sipgate als Aktivität an den passenden Vorgang';

    public function handle(SipgateSync $sync): int
    {
        if (! SipgateClient::isConfigured()) {
            $this->warn('sipgate ist nicht eingerichtet (SIPGATE_CLIENT_ID und SIPGATE_CLIENT_SECRET fehlen).');

            return self::SUCCESS;
        }

        $failed = 0;

        SipgateConnection::query()
            ->whereHas('user', fn ($q) => $q->where('is_blocked', false))
            ->with('user')
            ->each(function (SipgateConnection $connection) use ($sync, &$failed) {
                try {
                    $count = $sync->sync($connection);
                    $this->line("{$connection->user->name}: {$count} Anrufe übernommen");
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->error("{$connection->user->name}: Abgleich fehlgeschlagen ({$exception->getMessage()})");
                }
            });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
