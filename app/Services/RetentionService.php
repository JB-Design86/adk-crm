<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Document;
use App\Models\ImportLog;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\OrganizationCheck;
use App\Models\Participant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Löschlauf nach den Fristen des Löschkonzepts (config('adk.retention')).
 *
 * - Vorgang ohne Vertragsschluss, Betrieb: 24 Monate ab letztem Kontakt
 * - Vorgang ohne Vertragsschluss, Privatperson: 6 Monate ab letztem Kontakt
 * - Teilnehmerakte samt Vorgang, Förderfall und Dokumenten: 10 Jahre nach Ende der Maßnahme (A-22)
 * - Aktivitäten, Termine und Dokumente: mit dem Vorgang
 * - Organisationen und Kontakte: wenn kein Vorgang mehr darauf verweist
 * - Nachweis Einwilligung Telefonansprache: 5 Jahre ab Erteilung bzw. letzter Verwendung
 * - Importprotokoll: 3 Jahre ab Ende des Kalenderjahres
 * - Sperrliste: unbefristet, bleibt unberührt
 *
 * Gelöscht wird endgültig, samt der Protokolleinträge zu den gelöschten Datensätzen.
 * Im Protokoll bleiben nur Anzahl und Zeitpunkt des Löschlaufs.
 */
class RetentionService
{
    /** @var array<string, int> */
    public array $counts = [];

    /** @var array<string, list<string>> für den Probelauf */
    public array $details = [];

    public function __construct(private ?CarbonImmutable $now = null)
    {
        $this->now ??= CarbonImmutable::now();
    }

    /** @return array<string, int> */
    public function run(bool $dryRun = false): array
    {
        $this->counts = [];
        $this->details = [];

        $leads = $this->expiredLeads();
        $files = $this->expiredParticipantLeads();
        $consents = $this->expiredConsents();
        $importLogs = $this->expiredImportLogs();

        $this->record('leads_company', $leads['company']->map(fn (Lead $lead) => "Vorgang {$lead->id} (Betrieb, letzter Kontakt {$this->lastContact($lead)->format('d.m.Y')})"));
        $this->record('leads_private', $leads['private']->map(fn (Lead $lead) => "Vorgang {$lead->id} (Privatperson, letzter Kontakt {$this->lastContact($lead)->format('d.m.Y')})"));
        $this->record('participant_files', $files->map(fn (Lead $lead) => "Teilnehmerakte(n) an Vorgang {$lead->id} (Maßnahme beendet {$this->measureEnd($lead)?->format('d.m.Y')})"));
        $this->record('phone_consents', $consents->map(fn (Contact $contact) => "Einwilligungsnachweis an Kontakt {$contact->id}"));
        $this->record('import_logs', $importLogs->map(fn (ImportLog $log) => "Importprotokoll {$log->id} vom {$log->created_at->format('d.m.Y')}"));

        $leadIds = $leads['company']->merge($leads['private'])->merge($files)->pluck('id');

        if ($dryRun) {
            $this->counts['activities'] = Activity::whereIn('lead_id', $leadIds)->count();
            $this->counts['appointments'] = Appointment::whereIn('lead_id', $leadIds)->count();
            $this->counts['documents'] = Document::whereIn('lead_id', $leadIds)->count();
            $this->record('organizations', $this->orphanedOrganizations($leadIds)->map(fn (Organization $organization) => "Organisation {$organization->id}"));
            $this->record('contacts', $this->orphanedContacts($leadIds)->map(fn (Contact $contact) => "Kontakt {$contact->id}"));

            return $this->counts;
        }

        DB::transaction(function () use ($leadIds, $consents, $importLogs) {
            AuditLog::$allowDeletion = true;

            try {
                $this->deleteLeads($leadIds);
                $this->eraseConsents($consents);
                $this->deleteOrphans();
                $this->deleteImportLogs($importLogs);
            } finally {
                AuditLog::$allowDeletion = false;
            }

            activity('retention')
                ->event('retention')
                ->withProperties(['counts' => $this->counts])
                ->log('Löschlauf');
        });

        return $this->counts;
    }

    /** @return array{company: Collection<int, Lead>, private: Collection<int, Lead>} */
    private function expiredLeads(): array
    {
        $retention = config('adk.retention');
        $companyCutoff = $this->now->subMonths($retention['lead_company_months']);
        $privateCutoff = $this->now->subMonths($retention['lead_private_months']);

        $candidates = Lead::query()
            ->with('contact')
            ->whereNull('contracted_at')
            ->where(fn (Builder $q) => $q
                ->where('last_contact_at', '<', $privateCutoff)
                ->orWhere(fn (Builder $n) => $n->whereNull('last_contact_at')->where('created_at', '<', $privateCutoff)))
            ->get();

        $private = $candidates->filter(fn (Lead $lead) => $lead->isPrivatePerson())->values();
        $company = $candidates
            ->reject(fn (Lead $lead) => $lead->isPrivatePerson())
            ->filter(fn (Lead $lead) => $this->lastContact($lead)->lt($companyCutoff))
            ->values();

        return ['company' => $company, 'private' => $private];
    }

    /** @return Collection<int, Contact> */
    private function expiredConsents(): Collection
    {
        $cutoff = $this->now->subYears(config('adk.retention.phone_consent_years'))->toDateString();

        return Contact::query()
            ->where(fn (Builder $q) => $q->whereNotNull('phone_consent_at')->orWhereNotNull('phone_consent_proof'))
            ->get()
            ->filter(function (Contact $contact) use ($cutoff) {
                $reference = collect([$contact->phone_consent_at, $contact->phone_consent_last_used_at])->filter()->max();

                return $reference === null || $reference->toDateString() < $cutoff;
            })
            ->values();
    }

    /** @return Collection<int, ImportLog> */
    private function expiredImportLogs(): Collection
    {
        // Frist beginnt am Ende des Kalenderjahres: Import 2026 → löschen ab 01.01.2030 (bei 3 Jahren).
        $cutoff = $this->now->startOfYear()->subYears(config('adk.retention.import_log_years'));

        return ImportLog::where('created_at', '<', $cutoff)->get();
    }

    /**
     * Vorgänge mit Teilnehmerakte, deren Akten alle abgelaufen sind: zehn Jahre nach Ende der Maßnahme.
     * Akten ohne Austritts- und Kursende-Datum bleiben (Frist unbestimmt).
     *
     * @return Collection<int, Lead>
     */
    private function expiredParticipantLeads(): Collection
    {
        $years = config('adk.retention.participant_years');

        return Lead::query()
            ->whereNotNull('contracted_at')
            ->whereHas('participants')
            ->with('participants')
            ->get()
            ->filter(fn (Lead $lead) => $lead->participants->every(fn (Participant $p) => $p->measureEndedOn()?->addYears($years)->lt($this->now) ?? false))
            ->values();
    }

    private function measureEnd(Lead $lead): ?CarbonImmutable
    {
        return $lead->participants->map(fn (Participant $p) => $p->measureEndedOn())->filter()->max();
    }

    /** @param Collection<int, int> $leadIds */
    private function deleteLeads(Collection $leadIds): void
    {
        $counts = app(RecordPurger::class)->leads($leadIds);
        $this->counts['activities'] = $counts['activities'];
        $this->counts['appointments'] = $counts['appointments'];
        $this->counts['documents'] = $counts['documents'];
    }

    /** @param Collection<int, Contact> $contacts */
    private function eraseConsents(Collection $contacts): void
    {
        foreach ($contacts as $contact) {
            Contact::whereKey($contact->id)->update([
                'phone_consent_at' => null,
                'phone_consent_proof' => null,
                'phone_consent_last_used_at' => null,
            ]);

            // Nachweis auch aus den Änderungsprotokollen entfernen.
            AuditLog::where('subject_type', Contact::class)
                ->where('subject_id', $contact->id)
                ->whereNotNull('attribute_changes')
                ->get(['id', 'attribute_changes'])
                ->each(function (AuditLog $entry) {
                    $changes = $entry->attribute_changes->map(fn ($values) => is_array($values)
                        ? collect($values)->except(['phone_consent_at', 'phone_consent_proof', 'phone_consent_last_used_at'])->all()
                        : $values);

                    DB::table('activity_log')->where('id', $entry->id)->update(['attribute_changes' => $changes->toJson()]);
                });
        }
    }

    private function deleteOrphans(): void
    {
        $contacts = $this->orphanedContacts(collect());
        $this->purgeAuditLog(Contact::class, $contacts->pluck('id'));
        $this->counts['contacts'] = Contact::whereIn('id', $contacts->pluck('id'))->delete();

        $organizations = $this->orphanedOrganizations(collect());
        $organizationIds = $organizations->pluck('id');
        $orphanContacts = Contact::whereIn('organization_id', $organizationIds)->whereDoesntHave('leads')->whereDoesntHave('participants')->pluck('id');
        $this->purgeAuditLog(Contact::class, $orphanContacts);
        $this->counts['contacts'] += Contact::whereIn('id', $orphanContacts)->delete();
        $checkIds = OrganizationCheck::whereIn('organization_id', $organizationIds)->pluck('id');
        $this->purgeAuditLog(OrganizationCheck::class, $checkIds);
        OrganizationCheck::whereIn('id', $checkIds)->delete();
        $this->purgeAuditLog(Organization::class, $organizationIds);
        $this->counts['organizations'] = Organization::whereIn('id', $organizationIds)->delete();
    }

    /**
     * Organisationen ohne Vorgang. $deletedLeadIds: im Probelauf als bereits gelöscht betrachten.
     * Neu angelegte Datensätze ohne Vorgang bleiben orphan_grace_days lang erhalten.
     *
     * @param  Collection<int, int>  $deletedLeadIds
     * @return Collection<int, Organization>
     */
    private function orphanedOrganizations(Collection $deletedLeadIds): Collection
    {
        return Organization::query()
            ->whereDoesntHave('leads', fn (Builder $q) => $q->whereNotIn('id', $deletedLeadIds))
            ->where('updated_at', '<', $this->graceCutoff())
            ->get(['id']);
    }

    /**
     * @param  Collection<int, int>  $deletedLeadIds
     * @return Collection<int, Contact>
     */
    private function orphanedContacts(Collection $deletedLeadIds): Collection
    {
        return Contact::query()
            ->whereDoesntHave('leads', fn (Builder $q) => $q->whereNotIn('id', $deletedLeadIds))
            ->whereDoesntHave('participants', fn (Builder $q) => $q->whereNotIn('lead_id', $deletedLeadIds))
            ->where('updated_at', '<', $this->graceCutoff())
            ->where(fn (Builder $q) => $q
                ->whereNull('organization_id')
                ->orWhereHas('organization', fn (Builder $o) => $o->whereDoesntHave('leads', fn (Builder $l) => $l->whereNotIn('id', $deletedLeadIds))))
            ->get(['id']);
    }

    /** @param Collection<int, ImportLog> $logs */
    private function deleteImportLogs(Collection $logs): void
    {
        $this->purgeAuditLog(ImportLog::class, $logs->pluck('id'));
        ImportLog::whereIn('id', $logs->pluck('id'))->delete();
    }

    /** @param Collection<int, int>|iterable<int> $ids */
    private function purgeAuditLog(string $subjectType, iterable $ids): void
    {
        foreach (collect($ids)->chunk(500) as $chunk) {
            AuditLog::where('subject_type', $subjectType)->whereIn('subject_id', $chunk)->delete();
        }
    }

    private function graceCutoff(): CarbonImmutable
    {
        return $this->now->subDays(config('adk.retention.orphan_grace_days', 7));
    }

    private function lastContact(Lead $lead): CarbonImmutable
    {
        return CarbonImmutable::instance($lead->last_contact_at ?? $lead->created_at);
    }

    /** @param Collection<int, string> $lines */
    private function record(string $key, Collection $lines): void
    {
        $this->counts[$key] = $lines->count();
        $this->details[$key] = $lines->all();
    }
}
