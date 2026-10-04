<?php

namespace App\Console\Commands;

use App\Services\Duplicates\DuplicateFinder;
use Illuminate\Console\Command;

/**
 * Ganzen Bestand auf Dubletten prüfen, z. B. einmalig nach der Einführung der Dublettenprüfung.
 * Bereits entschiedene Paare werden nicht wieder gemeldet.
 */
class DuplicateScanCommand extends Command
{
    protected $signature = 'adk:dubletten-pruefen';

    protected $description = 'Prüft alle Organisationen und Kontakte auf Dubletten und legt Verdachtsfälle zur Prüfung an';

    public function handle(DuplicateFinder $finder): int
    {
        $count = $finder->scanAll();
        $this->info("{$count} neue Verdachtsfälle (Stammdaten → Dublettenprüfung).");

        return self::SUCCESS;
    }
}
