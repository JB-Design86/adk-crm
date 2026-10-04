<?php

namespace App\Services;

use App\Models\BlocklistEntry;
use App\Models\Contact;
use App\Models\Document;
use App\Models\ImportLog;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\User;
use App\Support\Normalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Löschersuchen nach Art. 17 DSGVO: Person bzw. Betrieb sofort und endgültig löschen,
 * samt Vorgängen, Aktivitäten, Terminen, Förderfällen, Dokumenten und Protokolleinträgen.
 *
 * - Teilnehmerakten unterliegen der Aufbewahrungspflicht (zehn Jahre, A-22, Art. 17 Abs. 3 lit. b):
 *   Solange es eine gibt, wird nicht gelöscht.
 * - Ansprechperson eines Betriebs: Die Person wird gelöscht, die Vorgänge des Betriebs bleiben.
 * - Auf Wunsch kommen Telefon, E-Mail bzw. Firmenname mit PLZ auf die Sperrliste, damit
 *   ein späterer Import die Person nicht wieder anlegt (Werbewiderspruch, Art. 21 DSGVO).
 * - Im Protokoll bleibt nur, dass gelöscht wurde, mit Anlass und Anzahl, ohne Namen.
 */
class ErasureService
{
    public function __construct(private RecordPurger $purger) {}

    /**
     * Was gelöscht würde und was dagegen spricht.
     *
     * @return array{leads: int, contacts: int, documents: int, activities: int, keeps_leads: int, blockers: list<string>}
     */
    public function preview(Organization|Contact $subject): array
    {
        [$leadIds, $contactIds, $detachLeadIds] = $this->scope($subject);

        $participants = Participant::query()
            ->where(fn ($q) => $q->whereIn('lead_id', $leadIds)->orWhereIn('contact_id', $contactIds))
            ->get();

        return [
            'leads' => $leadIds->count(),
            'contacts' => $contactIds->count(),
            'documents' => Document::whereIn('lead_id', $leadIds)->count(),
            'activities' => DB::table('activities')->whereIn('lead_id', $leadIds)->count(),
            'keeps_leads' => $detachLeadIds->count(),
            'blockers' => $participants->map(fn (Participant $p) => "Teilnehmerakte {$p->number}: Aufbewahrungspflicht zehn Jahre nach Ende der Maßnahme (A-22). Bis dahin nicht löschen, sondern die Verarbeitung einschränken.")->values()->all(),
        ];
    }

    /** @return array<string, int> Anzahl gelöschter Datensätze */
    public function erase(Organization|Contact $subject, string $reason, bool $blocklist, ?User $user = null): array
    {
        $user ??= auth()->user();

        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Bitte geben Sie den Anlass an (z. B. „E-Mail vom 04.10.2026“).']);
        }

        if ($blockers = $this->preview($subject)['blockers']) {
            throw ValidationException::withMessages(['subject' => implode(' ', $blockers)]);
        }

        [$leadIds, $contactIds, $detachLeadIds] = $this->scope($subject);
        $type = $subject instanceof Organization ? 'organization' : 'contact';

        return DB::transaction(fn () => RecordPurger::withAuditDeletion(function () use ($subject, $reason, $blocklist, $user, $leadIds, $contactIds, $detachLeadIds, $type) {
            if ($blocklist) {
                $this->blocklist($subject, $reason, $user);
            }

            Lead::whereIn('id', $detachLeadIds)->update(['contact_id' => null]);
            $counts = $this->purger->leads($leadIds);
            $counts['contacts'] = $this->purger->contacts($contactIds);
            $counts['organizations'] = $subject instanceof Organization ? $this->purger->organizations(collect([$subject->id])) : 0;

            if ($subject instanceof Organization) {
                $this->scrubImportLogs($subject->name);
            }

            activity('erasure')
                ->causedBy($user)
                ->event('erased')
                ->withProperties(['type' => $type, 'counts' => $counts, 'reason' => $reason, 'blocklist' => $blocklist])
                ->log('Löschersuchen ausgeführt (Art. 17 DSGVO)');

            return $counts;
        }));
    }

    /**
     * Was gelöscht wird: Vorgänge, Kontakte, und Vorgänge, an denen nur die Ansprechperson entfernt wird.
     *
     * @return array{0: Collection<int, int>, 1: Collection<int, int>, 2: Collection<int, int>}
     */
    private function scope(Organization|Contact $subject): array
    {
        if ($subject instanceof Organization) {
            $leadIds = Lead::where('organization_id', $subject->id)->pluck('id');
            $contactIds = Contact::where('organization_id', $subject->id)->pluck('id')
                ->merge(Lead::whereIn('id', $leadIds)->whereNotNull('contact_id')->pluck('contact_id'))
                ->unique()
                ->values();

            return [$leadIds, $contactIds, collect()];
        }

        $leads = Lead::where('contact_id', $subject->id)->get(['id', 'organization_id']);

        // Vorgang ohne Betrieb gehört der Person; beim Betrieb wird nur die Ansprechperson entfernt.
        return [
            $leads->whereNull('organization_id')->pluck('id')->values(),
            collect([$subject->id]),
            $leads->whereNotNull('organization_id')->pluck('id')->values(),
        ];
    }

    private function blocklist(Organization|Contact $subject, string $reason, ?User $user): void
    {
        $note = 'Löschersuchen (Art. 17 DSGVO): '.$reason;

        if ($subject instanceof Organization) {
            BlocklistEntry::create([
                'phone_e164' => $subject->phone_e164,
                'email' => $subject->email,
                'company_name' => $subject->name,
                'postal_code' => $subject->postal_code,
                'reason' => $note,
                'created_by' => $user?->id,
            ]);

            return;
        }

        if ($subject->phone_e164 || $subject->email) {
            BlocklistEntry::create([
                'phone_e164' => $subject->phone_e164,
                'email' => $subject->email,
                'reason' => $note,
                'created_by' => $user?->id,
            ]);
        }
    }

    /** Übersprungene Zeilen im Importprotokoll nennen den Firmennamen: unkenntlich machen. */
    private function scrubImportLogs(string $name): void
    {
        $key = Normalizer::companyName($name);

        ImportLog::whereNotNull('skipped_rows')->get()->each(function (ImportLog $log) use ($key) {
            $rows = collect($log->skipped_rows)->map(fn (array $row) => Normalizer::companyName($row['name'] ?? null) === $key
                ? [...$row, 'name' => '(gelöscht)']
                : $row);

            if ($rows->all() !== $log->skipped_rows) {
                DB::table('import_logs')->where('id', $log->id)->update(['skipped_rows' => $rows->toJson()]);
            }
        });
    }
}
