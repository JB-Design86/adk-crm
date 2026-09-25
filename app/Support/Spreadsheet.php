<?php

namespace App\Support;

use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvReaderOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Options as CsvWriterOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Lesen und Schreiben von CSV- und XLSX-Dateien (openspout).
 * CSV: Trennzeichen (Semikolon, Komma, Tab) und Zeichensatz (UTF-8 oder Windows-1252) werden erkannt.
 */
class Spreadsheet
{
    public const EXTENSIONS = ['csv', 'txt', 'xlsx'];

    /**
     * Zeilen als Listen von Zeichenketten. Die erste Zeile ist die Kopfzeile.
     *
     * @return Generator<int, list<string>>
     */
    public static function rows(string $path, ?string $extension = null): Generator
    {
        $extension = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION));

        $reader = match ($extension) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => new CsvReader(self::csvOptions($path)),
            default => throw new InvalidArgumentException('Nur CSV- und XLSX-Dateien werden unterstützt.'),
        };

        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield array_map(self::stringify(...), $row->toArray());
                }

                break; // nur das erste Tabellenblatt
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<list<mixed>>  $rows
     */
    public static function write(string $path, string $format, array $header, iterable $rows): void
    {
        $writer = match ($format) {
            'xlsx' => new XlsxWriter,
            'csv' => new CsvWriter(tap(new CsvWriterOptions, fn ($options) => $options->FIELD_DELIMITER = ';')),
            default => throw new InvalidArgumentException("Unbekanntes Format: {$format}"),
        };

        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($header));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();
    }

    private static function csvOptions(string $path): CsvReaderOptions
    {
        $sample = (string) file_get_contents($path, length: 8192);
        $firstLine = strtok($sample, "\n") ?: '';

        $options = new CsvReaderOptions;
        $counts = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $options->FIELD_DELIMITER = (string) array_key_first($counts);

        if (! mb_check_encoding(preg_replace('/^\xEF\xBB\xBF/', '', $sample), 'UTF-8')) {
            $options->ENCODING = 'Windows-1252';
        }

        return $options;
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? '1' : '0',
            is_float($value) && floor($value) === $value => (string) (int) $value,
            default => trim((string) $value),
        };
    }
}
