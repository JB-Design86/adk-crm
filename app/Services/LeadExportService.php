<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use App\Support\Adk;
use App\Support\Spreadsheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Export der (gefilterten) Vorgangsliste als CSV oder XLSX. Nur Rolle Verwaltung.
 * Jeder Export wird protokolliert: wer, wann, wie viele Datensätze, welcher Filter.
 */
class LeadExportService
{
    public const HEADER = [
        'Vorgang', 'Status', 'Zielgruppe', 'Eingangskanal', 'Firma', 'Priorität', 'Branche', 'PLZ', 'Ort',
        'Telefon', 'E-Mail', 'Ansprechpartner', 'Telefon Ansprechpartner', 'E-Mail Ansprechpartner',
        'Anrufversuche', 'Wiedervorlage', 'zuständig', 'Cross-Selling', 'Wiedervorlage Cross-Selling',
        'letzter Kontakt', 'geschlossen am', 'Quelle', 'Abrufdatum',
    ];

    /**
     * @param  Builder<Lead>  $query
     * @param  array<string, mixed>  $filters  Beschreibung der Filter für das Protokoll
     * @return string Pfad der erzeugten Datei
     */
    public function export(Builder $query, string $format, array $filters, User $user): string
    {
        Gate::forUser($user)->authorize('export');

        if (! in_array($format, ['csv', 'xlsx'], true)) {
            throw new InvalidArgumentException("Unbekanntes Format: {$format}");
        }

        $path = tempnam(sys_get_temp_dir(), 'export').'.'.$format;
        $count = 0;

        $rows = (function () use ($query, &$count) {
            foreach ($query->with(['organization', 'contact', 'assignee'])->lazy(500) as $lead) {
                $count++;
                yield $this->row($lead);
            }
        })();

        Spreadsheet::write($path, $format, self::HEADER, $rows);

        activity('export')
            ->causedBy($user)
            ->event('export')
            ->withProperties([
                'format' => $format,
                'count' => $count,
                'filters' => $filters,
            ])
            ->log("Export Vorgangsliste ({$count} Datensätze)");

        return $path;
    }

    /** @return list<string|int|null> */
    private function row(Lead $lead): array
    {
        $organization = $lead->organization;
        $contact = $lead->contact;

        return [
            $lead->id,
            Adk::statusLabel($lead->status),
            Adk::targetGroupLabel($lead->target_group),
            Adk::channelLabel($lead->channel),
            $organization?->name,
            $organization?->priority,
            $organization?->industry,
            $organization?->postal_code,
            $organization?->city,
            $organization?->phone_display,
            $organization?->email,
            $contact?->fullName(),
            $contact?->phone_display,
            $contact?->email,
            $lead->call_attempts,
            $lead->next_action_at ? trim($lead->next_action_at->format('d.m.Y').' '.$lead->next_action_time) : null,
            $lead->assignee?->name,
            $lead->cross_selling ? 'ja' : 'nein',
            $lead->cross_selling_follow_up_at?->format('d.m.Y'),
            $lead->last_contact_at?->format('d.m.Y H:i'),
            $lead->closed_at?->format('d.m.Y H:i'),
            $organization?->source,
            $organization?->retrieved_at?->format('d.m.Y'),
        ];
    }
}
