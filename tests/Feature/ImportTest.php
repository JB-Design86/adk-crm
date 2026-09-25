<?php

use App\Models\AuditLog;
use App\Models\BlocklistEntry;
use App\Models\ImportLog;
use App\Models\Lead;
use App\Models\Organization;
use App\Services\ImportService;
use App\Support\Spreadsheet;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = loginAs('admin');
    $this->service = app(ImportService::class);
    $this->path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
});

afterEach(function () {
    @unlink($this->path);
});

function writeCsv(string $path, array $rows, string $delimiter = ';'): void
{
    $handle = fopen($path, 'w');
    fputcsv($handle, ['Firmenname', 'PLZ', 'Ort', 'Telefon', 'E-Mail', 'Website', 'Priorität', 'Branche', 'Nachname Ansprechpartner'], $delimiter, '"', '');

    foreach ($rows as $row) {
        fputcsv($handle, $row, $delimiter, '"', '');
    }

    fclose($handle);
}

function runImport(object $test, ?string $source = 'Leadliste Test', ?string $retrievedAt = '2026-09-20'): ImportLog
{
    $preview = $test->service->preview($test->path, 'csv');
    $mapping = $test->service->guessMapping($preview['header']);

    return $test->service->import($test->path, 'csv', 'leads.csv', $source, $retrievedAt, $mapping, $test->user);
}

it('verlangt Quelle und Abrufdatum, sonst wird nichts eingespielt', function (?string $source, ?string $retrievedAt) {
    writeCsv($this->path, [['Muster GmbH', '55116', 'Mainz', '06131 123456', '', '', 'A', '', '']]);

    expect(fn () => runImport($this, $source, $retrievedAt))->toThrow(ValidationException::class);
    expect(Organization::count())->toBe(0)->and(ImportLog::count())->toBe(0);
})->with([
    'ohne Quelle' => [null, '2026-09-20'],
    'ohne Abrufdatum' => ['Leadliste', null],
]);

it('importiert Betriebe mit Quelle, Abrufdatum und Vorgang', function () {
    writeCsv($this->path, [
        ['Muster GmbH', '55116', 'Mainz', '06131 123456', 'info@muster.example', 'www.muster.example', 'A', 'Steuerberatung', 'Meier'],
        ['Beispiel KG', '65183', 'Wiesbaden', '0611/987654', '', '', 'b', '', ''],
    ]);

    $log = runImport($this);

    expect($log)->rows_total->toBe(2)->rows_imported->toBe(2)->rows_skipped->toBe(0);

    $organization = Organization::where('name', 'Muster GmbH')->first();
    expect($organization)
        ->source->toBe('Leadliste Test')
        ->retrieved_at->toDateString()->toBe('2026-09-20')
        ->phone_e164->toBe('+496131123456')
        ->website_domain->toBe('muster.example')
        ->priority->toBe('A')
        ->wz_code->toBe('69.20')
        ->import_log_id->toBe($log->id);

    expect(Organization::where('name', 'Beispiel KG')->first()->priority)->toBe('B');

    $lead = Lead::where('organization_id', $organization->id)->first();
    expect($lead)->status->toBe('new')->channel->toBe('cold_call')->contact->last_name->toBe('Meier');
});

it('überspringt Dubletten nach Telefon, Website und Firmenname mit PLZ', function () {
    Organization::factory()->create(['name' => 'Bestand GmbH', 'postal_code' => '55116', 'phone_display' => '06131 111111', 'website' => 'https://bestand.example']);

    writeCsv($this->path, [
        ['Anderer Name', '60311', 'Frankfurt', '+49 6131 111111', '', '', '', '', ''],     // Telefon
        ['Noch einer', '60311', 'Frankfurt', '', '', 'http://www.bestand.example/', '', '', ''], // Website
        ['Bestand UG', '55116', 'Mainz', '', '', '', '', '', ''],                           // Name + PLZ
        ['Neu GmbH', '55122', 'Mainz', '06131 222222', '', '', '', '', ''],
        ['Neu GmbH', '55122', 'Mainz', '', '', '', '', '', ''],                             // in der Datei
    ]);

    $log = runImport($this);

    expect($log)->rows_total->toBe(5)->rows_imported->toBe(1)->rows_skipped->toBe(4)->duplicates->toBe(4);
    expect(collect($log->skipped_rows)->pluck('reason')->all())
        ->sequence(
            fn ($reason) => $reason->toContain('gleiche Telefonnummer'),
            fn ($reason) => $reason->toContain('gleiche Website'),
            fn ($reason) => $reason->toContain('gleicher Firmenname und gleiche PLZ'),
            fn ($reason) => $reason->toContain('Dublette in der Datei'),
        );
});

it('überspringt Zeilen auf der Sperrliste und nennt den Grund im Protokoll', function () {
    BlocklistEntry::create(['phone_e164' => '+496131333333', 'reason' => 'Werbewiderspruch']);
    BlocklistEntry::create(['email' => 'nein@danke.example', 'reason' => 'Werbewiderspruch']);
    BlocklistEntry::create(['company_name' => 'Gesperrt GmbH', 'postal_code' => '55116', 'reason' => 'Werbewiderspruch']);

    writeCsv($this->path, [
        ['Eins GmbH', '55116', 'Mainz', '06131-333333', '', '', '', '', ''],
        ['Zwei GmbH', '55116', 'Mainz', '', 'Nein@Danke.example', '', '', '', ''],
        ['Gesperrt GmbH & Co. KG', '55116', 'Mainz', '', '', '', '', '', ''],
        ['Frei GmbH', '55116', 'Mainz', '', '', '', '', '', ''],
    ]);

    $log = runImport($this);

    expect($log)->rows_imported->toBe(1)->rows_skipped->toBe(3)->duplicates->toBe(0);
    expect(collect($log->skipped_rows)->pluck('type')->unique()->all())->toBe(['blocklist']);
    expect(Organization::pluck('name')->all())->toBe(['Frei GmbH']);
});

it('schreibt ein Importprotokoll mit Datei, Quelle, Person und Zeitpunkt', function () {
    writeCsv($this->path, [
        ['Muster GmbH', '55116', 'Mainz', '06131 123456', '', '', '', '', ''],
        ['', '55116', 'Mainz', '06131 999999', '', '', '', '', ''],
    ]);

    $log = runImport($this);

    expect($log->fresh())
        ->file_name->toBe('leads.csv')
        ->source->toBe('Leadliste Test')
        ->user_id->toBe($this->user->id)
        ->rows_total->toBe(2)
        ->rows_imported->toBe(1)
        ->rows_skipped->toBe(1)
        ->created_at->not->toBeNull()
        ->and($log->skipped_rows[0]['reason'])->toBe('Firmenname fehlt');

    expect(AuditLog::where('log_name', 'import')->where('causer_id', $this->user->id)->exists())->toBeTrue();
});

it('liest Komma-getrennte CSV in Windows-1252 und XLSX', function () {
    file_put_contents($this->path, mb_convert_encoding("Firmenname,PLZ,Ort\nMüller Straßenbau GmbH,55116,Mainz\n", 'Windows-1252', 'UTF-8'));
    $rows = iterator_to_array(Spreadsheet::rows($this->path, 'csv'), false);
    expect($rows[1][0])->toBe('Müller Straßenbau GmbH');

    $xlsx = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    Spreadsheet::write($xlsx, 'xlsx', ['Firmenname', 'PLZ'], [['Xlsx GmbH', '55116']]);
    $rows = iterator_to_array(Spreadsheet::rows($xlsx, 'xlsx'), false);
    expect($rows[1])->toBe(['Xlsx GmbH', '55116']);
    @unlink($xlsx);
});

it('ordnet die Spalten der Mustervorlage automatisch zu', function () {
    $header = array_map(fn ($field) => $field['label'], ImportService::FIELDS);
    $mapping = $this->service->guessMapping(array_values($header));

    expect(array_filter($mapping, fn ($index) => $index === null))->toBeEmpty();
});
