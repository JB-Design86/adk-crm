<?php

namespace App\Services;

use App\Models\BlocklistEntry;
use App\Models\CheckLevel;
use App\Models\Contact;
use App\Models\ImportLog;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Support\Normalizer;
use App\Support\Phone;
use App\Support\Spreadsheet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Import der Leadliste (CSV oder XLSX).
 *
 * - Quelle und Abrufdatum sind Pflicht, ohne sie wird nichts eingespielt.
 * - Dubletten (Telefon, Website-Domain, Firmenname + PLZ) werden übersprungen.
 * - Treffer auf der Sperrliste werden übersprungen.
 * - Jeder Import schreibt ein Importprotokoll.
 */
class ImportService
{
    /**
     * Feste Zielfelder. Die Bezeichnung ist zugleich die Spaltenüberschrift der Mustervorlage.
     * aliases: weitere Überschriften, die automatisch zugeordnet werden.
     * Die Prüfstufen kommen dynamisch aus der Tabelle check_levels hinzu, siehe fields().
     */
    public const BASE_FIELDS = [
        'name' => ['label' => 'Firmenname', 'aliases' => ['firma', 'unternehmen', 'name', 'firmenname']],
        'legal_form' => ['label' => 'Rechtsform', 'aliases' => []],
        'industry' => ['label' => 'Branche', 'aliases' => []],
        'wz_code' => ['label' => 'WZ-2008-Code', 'aliases' => ['wz', 'wz code', 'wz-code', 'wz 2008']],
        'priority' => ['label' => 'Priorität', 'aliases' => ['prioritaet', 'prio']],
        'street' => ['label' => 'Straße', 'aliases' => ['strasse', 'anschrift', 'adresse']],
        'postal_code' => ['label' => 'PLZ', 'aliases' => ['postleitzahl']],
        'city' => ['label' => 'Ort', 'aliases' => ['stadt']],
        'phone' => ['label' => 'Telefon', 'aliases' => ['telefonnummer', 'tel', 'tel.']],
        'email' => ['label' => 'E-Mail', 'aliases' => ['email', 'mail']],
        'website' => ['label' => 'Website', 'aliases' => ['webseite', 'internet', 'homepage', 'url']],
        'employee_count' => ['label' => 'Mitarbeitende', 'aliases' => ['mitarbeiter', 'größe', 'groesse', 'anzahl mitarbeitende']],
        'is_training_company' => ['label' => 'Ausbildungsbetrieb', 'aliases' => []],
        'check_notes' => ['label' => 'Bemerkung Prüfung', 'aliases' => ['bemerkung']],
        'contact_salutation' => ['label' => 'Anrede Ansprechpartner', 'aliases' => ['anrede']],
        'contact_first_name' => ['label' => 'Vorname Ansprechpartner', 'aliases' => ['vorname']],
        'contact_last_name' => ['label' => 'Nachname Ansprechpartner', 'aliases' => ['nachname', 'ansprechpartner']],
        'contact_position' => ['label' => 'Funktion Ansprechpartner', 'aliases' => ['funktion', 'position']],
        'contact_phone' => ['label' => 'Telefon Ansprechpartner', 'aliases' => ['durchwahl']],
        'contact_email' => ['label' => 'E-Mail Ansprechpartner', 'aliases' => []],
    ];

    public const CHECK_PREFIX = 'check_level_';

    /**
     * Alle Zielfelder: feste Felder plus je aktiver Prüfstufe eine Spalte
     * (Schlüssel check_level_{id}), eingefügt vor „Bemerkung Prüfung“.
     *
     * @return array<string, array{label: string, aliases: list<string>}>
     */
    public static function fields(): array
    {
        $fields = [];

        foreach (self::BASE_FIELDS as $key => $definition) {
            if ($key === 'check_notes') {
                foreach (CheckLevel::activeOrdered() as $level) {
                    $fields[self::CHECK_PREFIX.$level->id] = ['label' => $level->name, 'aliases' => []];
                }
            }

            $fields[$key] = $definition;
        }

        return $fields;
    }

    /**
     * Mustervorlage (XLSX) mit den aktuellen Spalten, inklusive der aktiven Prüfstufen,
     * und zwei erfundenen Beispielzeilen.
     */
    public static function writeTemplate(string $path): void
    {
        $examples = [
            [
                'name' => 'Beispiel Steuerberatung GmbH', 'legal_form' => 'GmbH', 'industry' => 'Steuerberatung', 'wz_code' => '69.20',
                'priority' => 'A', 'street' => 'Musterstraße 1', 'postal_code' => '55116', 'city' => 'Mainz',
                'phone' => '06131 000000', 'email' => 'info@beispiel-steuer.example', 'website' => 'https://www.beispiel-steuer.example',
                'employee_count' => '12', 'is_training_company' => 'ja', 'check_notes' => 'erfundene Beispielzeile',
                'contact_salutation' => 'Frau', 'contact_first_name' => 'Erika', 'contact_last_name' => 'Mustermann',
                'contact_position' => 'Geschäftsführung', 'contact_phone' => '06131 000001', 'contact_email' => 'e.mustermann@beispiel-steuer.example',
                'checks' => 'ja',
            ],
            [
                'name' => 'Muster Logistik KG', 'legal_form' => 'KG', 'industry' => 'Spedition und Logistik', 'wz_code' => '52.29',
                'priority' => 'B', 'street' => 'Beispielweg 5', 'postal_code' => '65428', 'city' => 'Rüsselsheim am Main',
                'phone' => '06142 000000', 'website' => 'www.muster-logistik.example', 'employee_count' => '45', 'is_training_company' => 'nein',
                'checks' => 'nein',
            ],
        ];

        $fields = self::fields();
        $rows = array_map(fn (array $example) => array_values(array_map(
            fn (string $key) => str_starts_with($key, self::CHECK_PREFIX) ? $example['checks'] : ($example[$key] ?? ''),
            array_keys($fields),
        )), $examples);

        Spreadsheet::write($path, 'xlsx', array_values(array_column($fields, 'label')), $rows);
    }

    /**
     * Kopfzeile und die ersten Datenzeilen für die Vorschau.
     *
     * @return array{header: list<string>, rows: list<list<string>>, total: int}
     */
    public function preview(string $path, string $extension, int $limit = 5): array
    {
        $header = null;
        $rows = [];
        $total = 0;

        foreach (Spreadsheet::rows($path, $extension) as $row) {
            if ($header === null) {
                $header = $row;

                continue;
            }

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $total++;

            if (count($rows) < $limit) {
                $rows[] = $row;
            }
        }

        if ($header === null) {
            throw ValidationException::withMessages(['file' => 'Die Datei enthält keine Zeilen.']);
        }

        return ['header' => $header, 'rows' => $rows, 'total' => $total];
    }

    /**
     * Automatische Zuordnung: Zielfeld => Spaltenindex.
     *
     * @param  list<string>  $header
     * @return array<string, int|null>
     */
    public function guessMapping(array $header): array
    {
        $normalized = array_map(fn ($title) => $this->normalizeHeader($title), $header);
        $mapping = [];

        foreach (self::fields() as $field => $definition) {
            $candidates = array_map(fn ($title) => $this->normalizeHeader($title), [$definition['label'], ...$definition['aliases']]);
            $index = null;

            foreach ($candidates as $candidate) {
                $found = array_search($candidate, $normalized, true);

                if ($found !== false && ! in_array($found, $mapping, true)) {
                    $index = $found;
                    break;
                }
            }

            $mapping[$field] = $index;
        }

        return $mapping;
    }

    /**
     * @param  array<string, int|string|null>  $mapping  Zielfeld => Spaltenindex
     */
    public function import(string $path, string $extension, string $fileName, ?string $source, mixed $retrievedAt, array $mapping, User $user): ImportLog
    {
        if (blank($source) || blank($retrievedAt)) {
            throw ValidationException::withMessages(array_filter([
                'source' => blank($source) ? 'Die Quelle ist Pflicht. Ohne Quelle wird nichts eingespielt.' : null,
                'retrieved_at' => blank($retrievedAt) ? 'Das Abrufdatum ist Pflicht. Ohne Abrufdatum wird nichts eingespielt.' : null,
            ]));
        }

        $retrievedAt = CarbonImmutable::parse($retrievedAt)->startOfDay();

        if ($retrievedAt->isFuture()) {
            throw ValidationException::withMessages(['retrieved_at' => 'Das Abrufdatum darf nicht in der Zukunft liegen.']);
        }

        $mapping = array_map(fn ($index) => $index === null || $index === '' ? null : (int) $index, $mapping);

        if (($mapping['name'] ?? null) === null) {
            throw ValidationException::withMessages(['mapping.name' => 'Bitte ordnen Sie die Spalte „Firmenname“ zu.']);
        }

        return DB::transaction(function () use ($path, $extension, $fileName, $source, $retrievedAt, $mapping, $user) {
            $log = ImportLog::create([
                'file_name' => $fileName,
                'source' => $source,
                'retrieved_at' => $retrievedAt,
                'user_id' => $user->id,
            ]);

            $seen = ['phone' => [], 'domain' => [], 'name_plz' => []];
            $skipped = [];
            $counts = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'duplicates' => 0];
            $line = 1;

            foreach (Spreadsheet::rows($path, $extension) as $row) {
                if ($line++ === 1) {
                    continue; // Kopfzeile
                }

                if ($this->isEmptyRow($row)) {
                    continue;
                }

                $counts['total']++;
                $data = $this->extract($row, $mapping);
                $reason = $this->skipReason($data, $seen);

                if ($reason !== null) {
                    $counts['skipped']++;
                    $isDuplicate = str_starts_with($reason, 'Dublette');
                    $counts['duplicates'] += $isDuplicate ? 1 : 0;
                    $skipped[] = [
                        'row' => $line - 1,
                        'name' => $data['name'],
                        'reason' => $reason,
                        'type' => $isDuplicate ? 'duplicate' : (str_starts_with($reason, 'Sperrliste') ? 'blocklist' : 'invalid'),
                    ];

                    continue;
                }

                $this->remember($data, $seen, $line - 1);
                $this->createRecords($data, $log, $source, $retrievedAt);
                $counts['imported']++;
            }

            $log->update([
                'rows_total' => $counts['total'],
                'rows_imported' => $counts['imported'],
                'rows_skipped' => $counts['skipped'],
                'duplicates' => $counts['duplicates'],
                'skipped_rows' => $skipped,
            ]);

            activity('import')
                ->causedBy($user)
                ->performedOn($log)
                ->event('import')
                ->withProperties([
                    'file_name' => $fileName,
                    'source' => $source,
                    'retrieved_at' => $retrievedAt->toDateString(),
                    'rows_total' => $counts['total'],
                    'rows_imported' => $counts['imported'],
                    'rows_skipped' => $counts['skipped'],
                    'duplicates' => $counts['duplicates'],
                ])
                ->log('Import');

            return $log;
        });
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int|null>  $mapping
     * @return array<string, mixed>
     */
    private function extract(array $row, array $mapping): array
    {
        $value = fn (string $field) => ($index = $mapping[$field] ?? null) === null ? null : (trim($row[$index] ?? '') ?: null);

        $data = [];

        $data['checks'] = [];

        foreach (array_keys(self::fields()) as $field) {
            $data[$field] = $value($field);

            if (str_starts_with($field, self::CHECK_PREFIX)) {
                $data['checks'][(int) substr($field, strlen(self::CHECK_PREFIX))] = $this->bool($data[$field]);
            }
        }

        $data['phone_e164'] = Phone::normalize($data['phone']);
        $data['contact_phone_e164'] = Phone::normalize($data['contact_phone']);
        $data['domain'] = Normalizer::domain($data['website']);
        $data['name_normalized'] = Normalizer::companyName($data['name']);
        $data['postal_code'] = Normalizer::postalCode($data['postal_code']);
        $data['priority'] = in_array(strtoupper((string) $data['priority']), config('adk.priorities'), true) ? strtoupper($data['priority']) : null;
        $data['employee_count'] = is_numeric($data['employee_count']) ? (int) $data['employee_count'] : null;
        $data['is_training_company'] = $this->bool($data['is_training_company']);

        if ($data['industry'] && ! $data['wz_code']) {
            $data['wz_code'] = config('adk.industries')[$data['industry']] ?? null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, int>>  $seen
     */
    private function skipReason(array $data, array $seen): ?string
    {
        if (blank($data['name']) || $data['name_normalized'] === null) {
            return 'Firmenname fehlt';
        }

        if ($data['email'] && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return 'E-Mail-Adresse ungültig';
        }

        $blocked = BlocklistEntry::findMatch($data['phone_e164'], $data['email'], $data['name'], $data['postal_code'])
            ?? BlocklistEntry::findMatch($data['contact_phone_e164'], $data['contact_email']);

        if ($blocked) {
            return 'Sperrliste (Eintrag vom '.$blocked->blocked_on->format('d.m.Y').')';
        }

        $namePlz = $data['postal_code'] ? $data['name_normalized'].'|'.$data['postal_code'] : null;

        // Dubletten innerhalb der Datei
        if ($data['phone_e164'] && isset($seen['phone'][$data['phone_e164']])) {
            return 'Dublette in der Datei: gleiche Telefonnummer wie Zeile '.$seen['phone'][$data['phone_e164']];
        }

        if ($data['domain'] && isset($seen['domain'][$data['domain']])) {
            return 'Dublette in der Datei: gleiche Website wie Zeile '.$seen['domain'][$data['domain']];
        }

        if ($namePlz && isset($seen['name_plz'][$namePlz])) {
            return 'Dublette in der Datei: gleicher Firmenname und gleiche PLZ wie Zeile '.$seen['name_plz'][$namePlz];
        }

        // Dubletten im Bestand
        if ($data['phone_e164'] && ($existing = Organization::where('phone_e164', $data['phone_e164'])->first())) {
            return "Dublette: gleiche Telefonnummer wie Organisation {$existing->id} ({$existing->name})";
        }

        if ($data['domain'] && ($existing = Organization::where('website_domain', $data['domain'])->first())) {
            return "Dublette: gleiche Website wie Organisation {$existing->id} ({$existing->name})";
        }

        if ($data['postal_code'] && ($existing = Organization::where('name_normalized', $data['name_normalized'])->where('postal_code', $data['postal_code'])->first())) {
            return "Dublette: gleicher Firmenname und gleiche PLZ wie Organisation {$existing->id} ({$existing->name})";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, int>>  $seen
     */
    private function remember(array $data, array &$seen, int $rowNumber): void
    {
        if ($data['phone_e164']) {
            $seen['phone'][$data['phone_e164']] = $rowNumber;
        }

        if ($data['domain']) {
            $seen['domain'][$data['domain']] = $rowNumber;
        }

        if ($data['postal_code']) {
            $seen['name_plz'][$data['name_normalized'].'|'.$data['postal_code']] = $rowNumber;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createRecords(array $data, ImportLog $log, string $source, CarbonImmutable $retrievedAt): void
    {
        $organization = Organization::create([
            'name' => $data['name'],
            'legal_form' => $data['legal_form'],
            'industry' => $data['industry'],
            'wz_code' => $data['wz_code'],
            'priority' => $data['priority'],
            'street' => $data['street'],
            'postal_code' => $data['postal_code'],
            'city' => $data['city'],
            'phone_display' => $data['phone'],
            'email' => $data['email'],
            'website' => $data['website'],
            'employee_count' => $data['employee_count'],
            'is_training_company' => $data['is_training_company'],
            'check_notes' => $data['check_notes'],
            'source' => $source,
            'retrieved_at' => $retrievedAt,
            'import_log_id' => $log->id,
        ]);

        $organization->syncChecks($data['checks']);

        $contact = null;

        if ($data['contact_last_name']) {
            $contact = Contact::create([
                'organization_id' => $organization->id,
                'salutation' => $data['contact_salutation'],
                'first_name' => $data['contact_first_name'],
                'last_name' => $data['contact_last_name'],
                'position' => $data['contact_position'],
                'phone_display' => $data['contact_phone'],
                'email' => filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL) ? $data['contact_email'] : null,
                'is_private' => false,
            ]);
        }

        Lead::create([
            'organization_id' => $organization->id,
            'contact_id' => $contact?->id,
            'target_group' => 'company_open',
            'channel' => 'cold_call',
            'status' => 'new',
            'import_log_id' => $log->id,
        ]);
    }

    /** @param list<string> $row */
    private function isEmptyRow(array $row): bool
    {
        return implode('', array_map('trim', $row)) === '';
    }

    private function normalizeHeader(string $title): string
    {
        return Str::of($title)->lower()->replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'])->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
    }

    private function bool(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return match (Str::lower(trim($value))) {
            '1', 'ja', 'j', 'x', 'yes', 'true', 'wahr', 'bestanden' => true,
            '0', 'nein', 'n', 'no', 'false', 'falsch', 'nicht bestanden', '-' => false,
            default => null,
        };
    }
}
