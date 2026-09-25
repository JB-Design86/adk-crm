<?php

namespace App\Console\Commands;

use App\Services\RetentionService;
use Illuminate\Console\Command;

/**
 * Täglicher Löschlauf nach den Fristen in config('adk.retention').
 * Mit --dry-run wird nur aufgelistet, was gelöscht würde.
 */
class RetentionCommand extends Command
{
    protected $signature = 'adk:loeschlauf {--dry-run : Probelauf, listet nur auf und löscht nichts}';

    protected $description = 'Löscht Daten nach Ablauf der Fristen des Löschkonzepts (Sperrliste bleibt unberührt)';

    private const LABELS = [
        'leads_company' => 'Vorgänge Betrieb (ohne Vertrag)',
        'leads_private' => 'Vorgänge Privatperson (ohne Vertrag)',
        'activities' => 'Aktivitäten (mit dem Vorgang)',
        'appointments' => 'Termine (mit dem Vorgang)',
        'organizations' => 'Organisationen ohne Vorgang',
        'contacts' => 'Kontakte ohne Vorgang',
        'phone_consents' => 'Nachweise Einwilligung Telefonansprache',
        'import_logs' => 'Importprotokolle',
    ];

    public function handle(RetentionService $retention): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info(($dryRun ? 'Probelauf' : 'Löschlauf').' am '.now()->format('d.m.Y H:i'));

        $counts = $retention->run($dryRun);

        $this->table(
            ['Datenart', $dryRun ? 'würde gelöscht' : 'gelöscht'],
            collect(self::LABELS)->map(fn (string $label, string $key) => [$label, $counts[$key] ?? 0])->values()->all(),
        );

        if ($dryRun) {
            foreach ($retention->details as $key => $lines) {
                if ($lines) {
                    $this->line(self::LABELS[$key].':');

                    foreach ($lines as $line) {
                        $this->line("  - {$line}");
                    }
                }
            }

            $this->comment('Probelauf: Es wurde nichts gelöscht.');
        } else {
            $this->info('Löschlauf abgeschlossen. Im Protokoll stehen nur Anzahl und Zeitpunkt.');
        }

        return self::SUCCESS;
    }
}
