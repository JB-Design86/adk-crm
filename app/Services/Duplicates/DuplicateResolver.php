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
 * Zusammenführen: Der vorhandene Datensatz bleibt (mit Verlauf und Nummer). Wo beide Einträge
 * verschiedene Werte haben, entscheidet die Auswahl im Dialog (Feld => 'match' oder 'subject');
 * ohne Auswahl bleibt der vorhandene Wert. Leere Felder werden aus dem neuen ergänzt,
 * Kontakte und Vorgänge mit Verlauf ziehen um, ein frisch importierter Vorgang ohne Verlauf
 * entfällt, der neue Datensatz wird gelöscht. Freigeben: Es sind verschiedene Datensätze;
 * dieses Paar wird nicht wieder gemeldet.
 */
class DuplicateResolver
{
    /** Felder mit Beschriftung für die Auswahl beim Zusammenführen. */
    public const LABELS = [
        'name' => 'Name', 'legal_form' => 'Rechtsform', 'industry' => 'Branche', 'wz_code' => 'WZ-Code', 'priority' => 'Priorität',
        'street' => 'Straße', 'postal_code' => 'PLZ', 'city' => 'Ort', 'phone_display' => 'Telefon', 'email' => 'E-Mail',
        'website' => 'Website', 'source_url' => 'Fundstelle', 'employee_count' => 'Mitarbeitende', 'is_training_company' => 'Ausbildungsbetrieb',
        'salutation' => 'Anrede', 'first_name' => 'Vorname', 'last_name' => 'Nachname', 'position' => 'Funktion',
        'privacy_notice_sent_at' => 'Datenschutzhinweis übermittelt am', 'phone_consent_at' => 'Einwilligung Telefon am',
        'phone_consent_proof' => 'Nachweis Einwilligung Telefon', 'health_consent_at' => 'Einwilligung Gesundheitsangaben am',
        'health_consent_proof' => 'Nachweis Einwilligung Gesundheitsangaben',
    ];

    private const HEALTH_FIELDS = ['health_consent_at', 'health_consent_proof'];

    public const ORGANIZATION_FIELDS = ['name', 'legal_form', 'industry', 'wz_code', 'priority', 'street', 'postal_code', 'city', 'phone_display', 'email', 'website', 'source_url', 'employee_count', 'is_training_company'];

    public const CONTACT_FIELDS = ['salutation', 'first_name', 'last_name', 'position', 'phone_display', 'email', 'privacy_notice_sent_at', 'phone_consent_at', 'phone_consent_proof', 'health_consent_at', 'health_consent_proof'];

    /** @param array<string, string> $choices Feld => 'match' (vorhandenen Wert behalten) oder 'subject' (neuen Wert übernehmen) */
    public function merge(DuplicateCandidate $candidate, ?User $user = null, array $choices = []): void
    {
        $this->assertOpen($candidate);
        $user ??= auth()->user();

        // Gesundheitsangaben nur entscheiden, wer sie sehen darf.
        if (! $user?->hasPermission('health.view')) {
            $choices = array_diff_key($choices, array_flip(self::HEALTH_FIELDS));
        }

        DB::transaction(function () use ($candidate, $user, $choices) {
            $subject = $candidate->subjectRecord();
            $match = $candidate->matchRecord();

            if (! $subject || ! $match) {
                $this->close($candidate, 'merged', $user);

                return;
            }

            $summary = $candidate->type === 'organization'
                ? $this->mergeOrganization($subject, $match, $choices)
                : $this->mergeContact($subject, $match, $choices);

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
    private function mergeOrganization(Organization $subject, Organization $match, array $choices = []): array
    {
        $filled = $this->apply($match, $subject, self::ORGANIZATION_FIELDS, $choices);
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
    private function mergeContact(Contact $subject, Contact $match, array $choices = []): array
    {
        $filled = $this->apply($match, $subject, self::CONTACT_FIELDS, $choices);

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

    /**
     * Werte übernehmen: gewählte Felder aus dem neuen Eintrag, sonst leere Felder ergänzen.
     *
     * @return list<string> geänderte Felder
     */
    private function apply(Organization|Contact $target, Organization|Contact $source, array $fields, array $choices = []): array
    {
        $changed = [];

        foreach ($fields as $field) {
            $takeNew = ($choices[$field] ?? null) === 'subject';

            if (filled($source->{$field}) && ($takeNew || blank($target->{$field}))) {
                $target->{$field} = $source->{$field};
                $changed[] = $field;
            }
        }

        $target->save();

        return $changed;
    }

    /**
     * Unterschiede für den Dialog: Konflikte (beide gefüllt, verschieden) zum Auswählen,
     * Ergänzungen (nur der neue gefüllt) werden automatisch übernommen.
     *
     * @return array{conflicts: array<string, array{label: string, match: string, subject: string}>, fills: array<string, string>}
     */
    public static function differences(Organization|Contact $match, Organization|Contact $subject, ?User $user = null): array
    {
        $fields = $match instanceof Organization ? self::ORGANIZATION_FIELDS : self::CONTACT_FIELDS;
        $result = ['conflicts' => [], 'fills' => []];

        foreach ($fields as $field) {
            if (in_array($field, self::HEALTH_FIELDS, true) && ! $user?->hasPermission('health.view')) {
                continue;
            }

            $old = self::display($match->{$field});
            $new = self::display($subject->{$field});

            if ($new === null || $old === $new) {
                continue;
            }

            if ($old === null) {
                $result['fills'][$field] = self::LABELS[$field].': '.$new;
            } else {
                $result['conflicts'][$field] = ['label' => self::LABELS[$field], 'match' => $old, 'subject' => $new];
            }
        }

        return $result;
    }

    private static function display(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '' => null,
            is_bool($value) => $value ? 'ja' : 'nein',
            $value instanceof \DateTimeInterface => $value->format('d.m.Y'),
            default => trim((string) $value) === '' ? null : trim((string) $value),
        };
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
