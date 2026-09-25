<?php

namespace App\Console\Commands;

use App\Services\ImportService;
use App\Support\Spreadsheet;
use Illuminate\Console\Command;

/**
 * Erzeugt die Mustervorlage für den Import mit den erwarteten Spalten.
 */
class ImportTemplateCommand extends Command
{
    protected $signature = 'adk:importvorlage {path=docs/import_vorlage.xlsx}';

    protected $description = 'Erzeugt die Mustervorlage für den Import (XLSX) mit erfundenen Beispielzeilen';

    public function handle(): int
    {
        $header = array_values(array_map(fn (array $field) => $field['label'], ImportService::FIELDS));

        $examples = [
            [
                'Beispiel Steuerberatung GmbH', 'GmbH', 'Steuerberatung', '69.20', 'A', 'Musterstraße 1', '55116', 'Mainz',
                '06131 000000', 'info@beispiel-steuer.example', 'https://www.beispiel-steuer.example', '12', 'ja',
                'ja', 'ja', 'ja', 'nein', '', 'erfundene Beispielzeile', 'Frau', 'Erika', 'Mustermann', 'Geschäftsführung',
                '06131 000001', 'e.mustermann@beispiel-steuer.example',
            ],
            [
                'Muster Logistik KG', 'KG', 'Spedition und Logistik', '52.29', 'B', 'Beispielweg 5', '65428', 'Rüsselsheim am Main',
                '06142 000000', '', 'www.muster-logistik.example', '45', 'nein',
                'ja', 'ja', 'nein', '', '', '', '', '', '', '', '', '',
            ],
        ];

        $path = base_path($this->argument('path'));
        Spreadsheet::write($path, 'xlsx', $header, $examples);

        $this->info("Mustervorlage geschrieben: {$this->argument('path')}");

        return self::SUCCESS;
    }
}
