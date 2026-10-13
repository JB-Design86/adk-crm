<?php

namespace App\Console\Commands;

use App\Services\HealthSectorCleanup;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Gesundheitswesen aus den Leadlisten aussortieren (HealthSectorCleanup). Ohne --loeschen nur Probelauf.
 * Keine Rückfrage im Terminal: Eingefügte Befehle brechen interaktive Fragen ab, --loeschen ist die Bestätigung.
 */
class HealthSectorCleanupCommand extends Command
{
    protected $signature = 'adk:gesundheit-aussortieren
        {--loeschen : Wirklich endgültig löschen (ohne: Probelauf, löscht nichts)}
        {--grenzfaelle : Auch Grenzfälle löschen (Tierärzte, Apotheken, Pflege, Optiker, Sanitätshäuser, Medizintechnik)}
        {--ohne= : Vorgangsnummern, die bleiben sollen, durch Komma getrennt, z. B. --ohne=12,345}
        {--liste : Im Probelauf alle Betriebe einzeln auflisten statt nur Beispiele}';

    protected $description = 'Löscht neue, unbearbeitete Vorgänge aus Leadlisten im Gesundheitswesen (Praxen, Ärzte, Therapeuten, Kliniken)';

    public function handle(HealthSectorCleanup $cleanup): int
    {
        $keep = collect(explode(',', (string) $this->option('ohne')))->map(fn (string $id) => (int) trim($id))->filter()->values()->all();
        $withBorderline = (bool) $this->option('grenzfaelle');
        $preview = $cleanup->preview($keep, $withBorderline);

        if (! $this->option('loeschen')) {
            $this->info('Probelauf am '.now()->format('d.m.Y H:i').': Es wird nichts gelöscht.');
            $this->newLine();
            $this->section('Würde gelöscht', $preview['delete'], (bool) $this->option('liste'));
            $this->section('Grenzfälle, bleiben (mit --grenzfaelle auch löschen)', $preview['borderline'], true);
            $this->section('Schon bearbeitet, bleiben (Anruf, Aktivität, Termin …)', $preview['worked'], true);

            if ($keep !== []) {
                $this->line('Ausgenommen: Vorgänge '.implode(', ', $keep));
            }

            $this->comment('Zum Löschen denselben Befehl mit --loeschen ausführen.');

            return self::SUCCESS;
        }

        $counts = $cleanup->run($keep, $withBorderline);

        $this->info('Aussortiert: '.$counts['leads'].' Vorgänge, '.$counts['organizations'].' Betriebe, '.$counts['contacts'].' Kontakte.');
        $this->line('Im Protokoll (Bereich „Aussortieren“) stehen nur die Anzahlen, keine Namen.');

        return self::SUCCESS;
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function section(string $title, Collection $rows, bool $all): void
    {
        $this->line("<options=bold>{$title}: {$rows->count()}</>");

        if ($rows->isEmpty()) {
            $this->newLine();

            return;
        }

        if ($all) {
            $this->table(['Nr.', 'Betrieb', 'Ort', 'Treffer'], $rows->map(fn (array $row) => [$row['id'], mb_strimwidth($row['name'], 0, 45, '…'), $row['place'], $row['hit']])->all());

            return;
        }

        // Nach Treffer gruppiert mit Beispielen, damit Fehlgriffe (z. B. „Praxis“ in einem anderen Namen) auffallen.
        $this->table(['Treffer', 'Anzahl', 'Beispiele (Nr. Betrieb)'], $rows->groupBy('hit')->sortByDesc(fn (Collection $group) => $group->count())
            ->map(fn (Collection $group, string $hit) => [$hit, $group->count(), $group->take(3)->map(fn (array $row) => $row['id'].' '.mb_strimwidth($row['name'], 0, 30, '…'))->join(' | ')])
            ->values()->all());
    }
}
