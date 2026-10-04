<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DuplicateCandidate;
use App\Models\FundingCase;
use App\Models\FundingCaseStep;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\OrganizationCheck;
use App\Models\Participant;
use App\Models\ParticipantCheck;
use App\Services\Documents\DocumentService;
use Closure;
use Illuminate\Support\Collection;

/**
 * Endgültiges Löschen samt zugehöriger Daten und Protokolleinträge.
 * Gemeinsam genutzt vom Löschlauf (Fristen) und von Löschersuchen (Art. 17 DSGVO).
 * Aufrufer laufen in einer Transaktion und über withAuditDeletion().
 */
class RecordPurger
{
    public function __construct(private DocumentService $documents) {}

    /** Protokolleinträge dürfen nur innerhalb dieses Aufrufs gelöscht werden. */
    public static function withAuditDeletion(Closure $callback): mixed
    {
        AuditLog::$allowDeletion = true;

        try {
            return $callback();
        } finally {
            AuditLog::$allowDeletion = false;
        }
    }

    /**
     * Vorgänge mit Dokumenten (Dateien), Teilnehmerakten, Förderfällen, Aktivitäten und Terminen.
     *
     * @param  Collection<int, int>  $leadIds
     * @return array{documents: int, activities: int, appointments: int, leads: int}
     */
    public function leads(Collection $leadIds): array
    {
        $counts = ['documents' => 0, 'activities' => 0, 'appointments' => 0, 'leads' => 0];

        foreach ($leadIds->chunk(500) as $chunk) {
            $documents = Document::whereIn('lead_id', $chunk)->get();
            $this->purgeAuditLog(Document::class, $documents->pluck('id'));
            $counts['documents'] += $this->documents->purge($documents);

            $participantIds = Participant::whereIn('lead_id', $chunk)->pluck('id');
            $checkIds = ParticipantCheck::whereIn('participant_id', $participantIds)->pluck('id');
            $this->purgeAuditLog(ParticipantCheck::class, $checkIds);
            ParticipantCheck::whereIn('id', $checkIds)->delete();
            $this->purgeAuditLog(Participant::class, $participantIds);
            Participant::whereIn('id', $participantIds)->delete();

            $activityIds = Activity::whereIn('lead_id', $chunk)->pluck('id');
            $appointmentIds = Appointment::whereIn('lead_id', $chunk)->pluck('id');
            $this->purgeAuditLog(Activity::class, $activityIds);
            $this->purgeAuditLog(Appointment::class, $appointmentIds);

            $caseIds = FundingCase::whereIn('lead_id', $chunk)->pluck('id');
            $caseStepIds = FundingCaseStep::whereIn('funding_case_id', $caseIds)->pluck('id');
            $this->purgeAuditLog(FundingCaseStep::class, $caseStepIds);
            $this->purgeAuditLog(FundingCase::class, $caseIds);
            FundingCaseStep::whereIn('id', $caseStepIds)->delete();
            FundingCase::whereIn('id', $caseIds)->delete();
            $this->purgeAuditLog(Lead::class, $chunk);

            $counts['activities'] += Activity::whereIn('id', $activityIds)->delete();
            $counts['appointments'] += Appointment::whereIn('id', $appointmentIds)->delete();
            $counts['leads'] += Lead::whereIn('id', $chunk)->delete();
        }

        return $counts;
    }

    /** @param Collection<int, int> $contactIds */
    public function contacts(Collection $contactIds): int
    {
        $this->purgeAuditLog(Contact::class, $contactIds);
        $this->purgeCandidates('contact', $contactIds);

        return Contact::whereIn('id', $contactIds)->delete();
    }

    /** @param Collection<int, int> $organizationIds */
    public function organizations(Collection $organizationIds): int
    {
        $checkIds = OrganizationCheck::whereIn('organization_id', $organizationIds)->pluck('id');
        $this->purgeAuditLog(OrganizationCheck::class, $checkIds);
        OrganizationCheck::whereIn('id', $checkIds)->delete();
        $this->purgeAuditLog(Organization::class, $organizationIds);
        $this->purgeCandidates('organization', $organizationIds);

        return Organization::whereIn('id', $organizationIds)->delete();
    }

    /** @param Collection<int, int>|iterable<int> $ids */
    public function purgeAuditLog(string $subjectType, iterable $ids): void
    {
        foreach (collect($ids)->chunk(500) as $chunk) {
            AuditLog::where('subject_type', $subjectType)->whereIn('subject_id', $chunk)->delete();
        }
    }

    /** @param Collection<int, int> $ids */
    private function purgeCandidates(string $type, Collection $ids): void
    {
        DuplicateCandidate::where('type', $type)
            ->where(fn ($q) => $q->whereIn('subject_id', $ids)->orWhereIn('match_id', $ids))
            ->delete();
    }
}
