<?php

namespace App\Console\Commands;

use App\Services\ImportService;
use Illuminate\Console\Command;

/**
 * Erzeugt die Mustervorlage für den Import mit den erwarteten Spalten
 * (inklusive der aktiven Prüfstufen aus der Datenbank).
 */
class ImportTemplateCommand extends Command
{
    protected $signature = 'adk:importvorlage {path=docs/import_vorlage.xlsx}';

    protected $description = 'Erzeugt die Mustervorlage für den Import (XLSX) mit erfundenen Beispielzeilen';

    public function handle(): int
    {
        ImportService::writeTemplate(base_path($this->argument('path')));

        $this->info("Mustervorlage geschrieben: {$this->argument('path')}");

        return self::SUCCESS;
    }
}
