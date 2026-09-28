<?php

use App\Models\Organization;
use App\Services\ImportService;
use App\Support\Spreadsheet;
use OpenSpout\Reader\XLSX\Reader;

/** @return array<string, list<list<mixed>>> Blattname => Zeilen */
function readWorkbook(string $path): array
{
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }

    $reader->close();

    return $sheets;
}

it('erzeugt die Vorlage mit den Blättern Leadliste, Erklärung und Branchen', function () {
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    ImportService::writeTemplate($path);
    $sheets = readWorkbook($path);
    @unlink($path);

    expect(array_keys($sheets))->toBe(['Leadliste', 'Erklärung', 'Branchen']);

    $columns = $sheets['Leadliste'][0];
    $explained = array_column(array_slice($sheets['Erklärung'], 1), 0);

    // Jede Spalte der Leadliste ist im Blatt Erklärung beschrieben.
    expect(array_diff($columns, $explained))->toBeEmpty()
        ->and($columns)->toContain('Fundstelle (URL)', 'Firmenname', 'Prüfstufe 1')
        ->and(count($sheets['Branchen']) - 1)->toBe(count(config('adk.industries')));

    $firmenname = collect($sheets['Erklärung'])->firstWhere(0, 'Firmenname');
    expect($firmenname[1])->toBe('Pflicht');
});

it('liest beim Import nur das Blatt Leadliste', function () {
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    ImportService::writeTemplate($path);

    $rows = iterator_to_array(Spreadsheet::rows($path, 'xlsx'), false);
    @unlink($path);

    expect($rows)->toHaveCount(3)
        ->and($rows[1][0])->toBe('Beispiel Steuerberatung GmbH');
});

it('übernimmt die Fundstelle beim Import aus der Vorlage', function () {
    $user = loginAs('admin');
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    ImportService::writeTemplate($path);

    $service = app(ImportService::class);
    $mapping = $service->guessMapping($service->preview($path, 'xlsx')['header']);
    $log = $service->import($path, 'xlsx', 'vorlage.xlsx', 'Vertriebs-Chat Test', '2026-09-20', $mapping, $user);
    @unlink($path);

    expect($log->rows_imported)->toBe(2)
        ->and(Organization::where('name', 'Beispiel Steuerberatung GmbH')->sole()->source_url)
        ->toBe('https://www.beispiel-steuer.example/impressum');
});
