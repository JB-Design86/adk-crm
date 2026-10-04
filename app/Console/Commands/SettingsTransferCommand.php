<?php

namespace App\Console\Commands;

use App\Models\CheckLevel;
use App\Models\FundingCase;
use App\Models\FundingStep;
use App\Models\OrganizationCheck;
use App\Models\Participant;
use App\Models\ParticipantChecklistItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Einstellungen zwischen Umgebungen übertragen (z. B. von crm-test in den Betrieb):
 * Prüfstufen, Förderweg-Schritte mit Erklärtexten, Checkliste der Teilnehmerakte.
 * Enthält keine personenbezogenen Daten. Eingespielt wird nur in ein System ohne
 * Förderfälle, Teilnehmerakten und Prüfergebnisse, damit keine Verweise brechen.
 */
class SettingsTransferCommand extends Command
{
    protected $signature = 'adk:einstellungen
        {aktion : export oder import}
        {datei : JSON-Datei}';

    protected $description = 'Exportiert bzw. importiert Prüfstufen, Förderweg-Schritte und Checkliste (ohne personenbezogene Daten)';

    private const TABLES = [
        'check_levels' => [CheckLevel::class, ['name', 'description', 'sort_order', 'is_active']],
        'funding_steps' => [FundingStep::class, ['pathway', 'name', 'instructions', 'default_party', 'follow_up_days', 'calendar_days', 'can_fail', 'document_category', 'requires_document', 'sort_order', 'is_active']],
        'participant_checklist_items' => [ParticipantChecklistItem::class, ['key', 'phase', 'name', 'instructions', 'document_category', 'repeatable', 'sort_order', 'is_active']],
    ];

    public function handle(): int
    {
        return match ($this->argument('aktion')) {
            'export' => $this->export($this->argument('datei')),
            'import' => $this->import($this->argument('datei')),
            default => $this->fail('Aktion bitte „export“ oder „import“.'),
        };
    }

    private function export(string $file): int
    {
        $data = ['exported_at' => now()->toIso8601String(), 'app_url' => config('app.url')];

        foreach (self::TABLES as $table => [, $columns]) {
            $data[$table] = DB::table($table)->orderBy('sort_order')->orderBy('id')->get($columns)->map(fn ($row) => (array) $row)->all();
        }

        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        foreach (self::TABLES as $table => $definition) {
            $this->line(str_pad($table, 30).count($data[$table]).' Einträge');
        }

        $this->info("Exportiert nach {$file}");

        return self::SUCCESS;
    }

    private function import(string $file): int
    {
        if (! is_readable($file)) {
            return $this->fail("Datei {$file} nicht lesbar.");
        }

        if (FundingCase::exists() || Participant::exists() || OrganizationCheck::exists()) {
            return $this->fail('Hier gibt es schon Förderfälle, Teilnehmerakten oder Prüfergebnisse. Einstellungen werden nur in ein neues System eingespielt.');
        }

        $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($data) {
            foreach (self::TABLES as $table => [$model, $columns]) {
                $rows = collect($data[$table] ?? [])->map(fn (array $row) => collect($row)->only($columns)->all());

                if ($rows->isEmpty()) {
                    continue;
                }

                $model::query()->delete();

                foreach ($rows as $row) {
                    $model::forceCreate($row);
                }

                $this->line(str_pad($table, 30).$rows->count().' Einträge');
            }
        });

        $this->info('Einstellungen eingespielt (Quelle: '.($data['app_url'] ?? '?').', Stand '.($data['exported_at'] ?? '?').').');

        return self::SUCCESS;
    }
}
