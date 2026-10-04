<?php

namespace App\Services\Duplicates;

use App\Models\Activity;
use App\Models\Contact;
use App\Models\DuplicateCandidate;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\OrganizationCheck;
use App\Models\Participant;
use App\Models\User;
use App\Support\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Entscheidung über einen Dublettenverdacht.
 *
 * Zusammenführen: Der vorhandene Datensatz bleibt. Leere Felder werden aus dem neuen ergänzt,
 * Kontakte und Vorgänge mit Verlauf ziehen um, ein frisch importierter Vorgang ohne Verlauf
 * entfällt, der neue Datensatz wird gelöscht. Freigeben: Es sind verschiedene Datensätze;
 * dieses Paar wird nicht wieder gemeldet.
 */
class DuplicateResolver
{
    private const ORGANIZATION_FIELDS = ['legal_form', 'industry', 'wz_code', 'priority', 'street', 'postal_code', 'city', 'phone_display', 'email', 'website', 'source_url', 'employee_count', 'is_training_company'];

    private const CONTACT_FIELDS = ['salutation', 'first_name', 'position', 'phone_display', 'email', 'privacy_notice_sent_at', 'phone_consent_at', 'phone_consent_proof', 'health_consent_at', 'health_consent_proof'];

    public function merge(DuplicateCandidate $candidate, ?User $user = null): void
    {
        $this->assertOpen($candidate);
        $user ??= auth()->user();

        DB::transaction(function () use ($candidate, $user) {
            $subject = $candidate->subjectRecord();
            $match = $candidate->matchRecord();

            if (! $subject || ! $match) {
                $this->close($candidate, 'merged', $user);

                return;
            }

            $summary = $candidate->type === 'organization'
                ? $this->mergeOrganization($subject, $match)
                : $this->mergeContact($subject, $match);

            activity('duplicates')
                ->causedBy($user)
                ->performedOn($match)
                ->event('resolved')
                ->withProperties(['result' => 'merged', 'merged_id' => $subject->getKey(), 'summary' => $summary])
                ->log('Dublette zusammengeführt');

            $this->close($candidate, 'merged', $user);

            // Weitere offene Verdachtsfälle zum gelöschten Datensatz sind erledigt.
            DuplicateCandidate::open()
                ->where('type', $candidate->type)
                ->where(fn ($q) => $q->where('subject_id', $subject->getKey())->orWhere('match_id', $subject->getKey()))
                ->delete();
        });
    }

    public function reject(DuplicateCandidate $candidate, ?User $user = null): void
    {
        $this->assertOpen($candidate);
        $user ??= auth()->user();

        activity('duplicates')
            ->causedBy($user)
            ->event('resolved')
            ->withProperties(['result' => 'rejected', 'type' => $candidate->type, 'subject_id' => $candidate->subject_id, 'match_id' => $candidate->match_id])
            ->log('Kein Duplikat (freigegeben)');

        $this->close($candidate, 'rejected', $user);
    }

    /** @return array<string, mixed> */
    private function mergeOrganization(Organization $subject, Organization $match): array
    {
        $filled = $this->fillEmpty($match, $subject, self::ORGANIZATION_FIELDS);
        $movedContacts = 0;
        $removedLeads = 0;
        $movedLeads = 0;

        foreach ($subject->contacts()->get() as $contact) {
            $twin = $match->contacts()->get()->first(fn (Contact $c) => ($contact->email && Normalizer::email($c->email) === Normalizer::email($contact->email))
                || (Normalizer::personName($c->first_name, $c->last_name) === Normalizer::personName($contact->first_name, $contact->last_name)));

            if ($twin) {
                $this->mergeContact($contact, $twin);
            } else {
                $contact->update(['organization_id' => $match->id]);
                $movedContacts++;
            }
        }

        foreach ($subject->leads()->get() as $lead) {
            if ($this->isUntouched($lead) && $match->leads()->exists()) {
                $lead->delete();
                $removedLeads++;
            } else {
                $lead->update(['organization_id' => $match->id]);
                $movedLeads++;
                $this->note($lead, "Zusammengeführt mit Organisation {$match->name}: doppelter Eintrag „{$subject->name}“ entfernt.");
            }
        }

        OrganizationCheck::where('organization_id', $subject->id)->delete();
        $subject->delete();

        return ['filled' => $filled, 'contacts_moved' => $movedContacts, 'leads_moved' => $movedLeads, 'leads_removed' => $removedLeads];
    }

    /** @return array<string, mixed> */
    private function mergeContact(Contact $subject, Contact $match): array
    {
        $filled = $this->fillEmpty($match, $subject, self::CONTACT_FIELDS);

        $leads = Lead::where('contact_id', $subject->id)->get();

        foreach ($leads as $lead) {
            if ($this->isUntouched($lead) && $match->leads()->exists()) {
                $lead->delete();
            } else {
                $lead->update(['contact_id' => $match->id]);
            }
        }

        Participant::where('contact_id', $subject->id)->update(['contact_id' => $match->id]);
        $subject->delete();

        return ['filled' => $filled, 'leads' => $leads->count()];
    }

    /** @return list<string> ergänzte Felder */
    private function fillEmpty(Organization|Contact $target, Organization|Contact $source, array $fields): array
    {
        $filled = [];

        foreach ($fields as $field) {
            if (blank($target->{$field}) && filled($source->{$field})) {
                $target->{$field} = $source->{$field};
                $filled[] = $field;
            }
        }

        $target->save();

        return $filled;
    }

    /** Frisch angelegter Vorgang ohne Verlauf (z. B. aus dem Import): kann entfallen. */
    private function isUntouched(Lead $lead): bool
    {
        return $lead->status === 'new'
            && ! $lead->activities()->exists()
            && ! $lead->appointments()->exists()
            && ! $lead->documents()->exists()
            && ! $lead->fundingCase()->exists();
    }

    private function note(Lead $lead, string $text): void
    {
        Activity::create(['lead_id' => $lead->id, 'type' => 'note', 'body' => $text, 'occurred_at' => $lead->last_contact_at ?? $lead->created_at]);
    }

    private function close(DuplicateCandidate $candidate, string $state, ?User $user): void
    {
        $candidate->update(['state' => $state, 'resolved_by' => $user?->id, 'resolved_at' => now()]);
    }

    private function assertOpen(DuplicateCandidate $candidate): void
    {
        if ($candidate->state !== 'open') {
            throw ValidationException::withMessages(['state' => 'Dieser Verdacht ist bereits entschieden.']);
        }
    }
}
