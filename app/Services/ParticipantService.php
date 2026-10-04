<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Contact;
use App\Models\FundingCase;
use App\Models\Participant;
use App\Models\ParticipantCheck;
use App\Models\ParticipantChecklistItem;
use App\Models\User;
use App\Services\Documents\DocumentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Teilnehmerakte (Stufe 4): Anlegen bei der Einschreibung, Checkliste, Abschluss, Verbleib.
 */
class ParticipantService
{
    /** Felder der Akte, die bei Anlage und Bearbeitung übernommen werden. */
    public const FIELDS = ['birth_date', 'street', 'postal_code', 'city', 'course_name', 'course_starts_on', 'course_ends_on', 'notes'];

    /**
     * Akte für die Person des Förderfalls anlegen (Bildungsgutschein, Arbeitsunfall, Selbstzahler).
     * Beim Betrieb (§ 82 SGB III) legt die Verwaltung die Beschäftigten einzeln an (addEmployee).
     */
    public function createForCase(FundingCase $case, array $data = [], ?User $user = null): ?Participant
    {
        $lead = $case->lead;

        if ($case->pathway === 'employer' || ! $lead->contact) {
            return null;
        }

        return $this->create($case, $lead->contact, $data, $user);
    }

    /** Beschäftigte eines Betriebs als Teilnehmer/in anlegen (Förderweg § 82 SGB III). */
    public function addEmployee(FundingCase $case, array $data, ?User $user = null): Participant
    {
        if ($case->state !== 'enrolled') {
            throw ValidationException::withMessages(['state' => 'Teilnehmende lassen sich erst nach „Einschreibung bestätigt“ anlegen.']);
        }

        return DB::transaction(function () use ($case, $data, $user) {
            $contact = Contact::create([
                'organization_id' => $case->lead->organization_id,
                'salutation' => $data['salutation'] ?? null,
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'],
                'email' => $data['email'] ?? null,
                'phone_display' => $data['phone_display'] ?? null,
                'is_private' => false,
            ]);

            return $this->create($case, $contact, $data, $user);
        });
    }

    private function create(FundingCase $case, Contact $contact, array $data, ?User $user): Participant
    {
        $user ??= auth()->user();

        return DB::transaction(function () use ($case, $contact, $data, $user) {
            $participant = Participant::create([
                ...collect($data)->only(self::FIELDS)->all(),
                'lead_id' => $case->lead_id,
                'funding_case_id' => $case->id,
                'contact_id' => $contact->id,
            ]);

            // Die Bestätigung des Kostenträgers ist Voraussetzung der Einschreibung: gleich abhaken.
            if ($item = ParticipantChecklistItem::where('key', 'funder_confirmed')->where('is_active', true)->first()) {
                ParticipantCheck::create([
                    'participant_id' => $participant->id,
                    'participant_checklist_item_id' => $item->id,
                    'done_on' => ($case->enrolled_at ?? now())->toDateString(),
                    'note' => 'Mit „Einschreibung bestätigt“ im Förderfall übernommen.',
                    'document_id' => $case->lead->documents()->where('category', 'funder_correspondence')->latest()->value('id'),
                    'user_id' => $user?->id,
                ]);
            }

            Activity::create([
                'lead_id' => $case->lead_id,
                'user_id' => $user?->id,
                'type' => 'status_change',
                'body' => "Teilnehmerakte {$participant->number} angelegt: {$contact->fullName()}.",
            ]);

            return $participant;
        });
    }

    /**
     * Punkt der Checkliste abhaken, optional mit Dokument.
     *
     * @param  array{done_on?: mixed, note?: ?string, file?: ?UploadedFile, category?: ?string, title?: ?string}  $data
     */
    public function completeCheck(Participant $participant, ParticipantChecklistItem $item, array $data, ?User $user = null): ParticipantCheck
    {
        $user ??= auth()->user();
        $doneOn = CarbonImmutable::parse($data['done_on'] ?? today());

        if ($doneOn->isAfter(today())) {
            throw ValidationException::withMessages(['done_on' => 'Das Datum darf nicht in der Zukunft liegen.']);
        }

        if (! $item->repeatable && $participant->checks()->where('participant_checklist_item_id', $item->id)->exists()) {
            throw ValidationException::withMessages(['item' => "„{$item->name}“ ist bereits abgehakt."]);
        }

        return DB::transaction(function () use ($participant, $item, $data, $user, $doneOn) {
            $document = null;

            if (($data['file'] ?? null) instanceof UploadedFile) {
                $document = app(DocumentService::class)->store($participant->lead, $data['file'], [
                    'category' => $data['category'] ?? $item->document_category ?? 'other',
                    'title' => $data['title'] ?? $item->name,
                    'document_date' => $doneOn->toDateString(),
                ], $participant, $user);
            }

            $check = ParticipantCheck::create([
                'participant_id' => $participant->id,
                'participant_checklist_item_id' => $item->id,
                'done_on' => $doneOn->toDateString(),
                'note' => $data['note'] ?? null,
                'document_id' => $document?->id,
                'user_id' => $user?->id,
            ]);

            // Eintrittsmeldung: Die Person ist jetzt im Kurs.
            if ($item->key === 'entry_report' && $participant->state === 'registered') {
                $participant->update(['state' => 'active']);
            }

            return $check;
        });
    }

    public function undoCheck(ParticipantCheck $check): void
    {
        $check->delete();
    }

    /**
     * Abschluss oder Abbruch. Danach automatisch Wiedervorlage zur Verbleibserhebung
     * (sechs Monate nach Kursende), sichtbar in „Heute“ und im Kalender.
     */
    public function finish(Participant $participant, string $outcome, mixed $leftOn, ?string $reason = null, ?User $user = null): void
    {
        if (! in_array($outcome, ['completed', 'dropped'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Bitte wählen Sie Abschluss oder Abbruch.']);
        }

        if ($outcome === 'dropped' && blank($reason)) {
            throw ValidationException::withMessages(['exit_reason' => 'Bitte geben Sie einen Grund für den Abbruch an.']);
        }

        $leftOn = CarbonImmutable::parse($leftOn);
        $followUp = $leftOn->addMonthsNoOverflow(config('adk.placement_follow_up_months'));
        $user ??= auth()->user();

        DB::transaction(function () use ($participant, $outcome, $leftOn, $reason, $followUp, $user) {
            $participant->update([
                'state' => $outcome,
                'left_on' => $leftOn->toDateString(),
                'exit_reason' => $reason,
                'follow_up_on' => $followUp->toDateString(),
            ]);

            $lead = $participant->lead;
            $lead->next_action_at = $lead->next_action_at && $lead->next_action_at->lt($followUp) ? $lead->next_action_at : $followUp;
            $lead->save();

            Activity::create([
                'lead_id' => $lead->id,
                'user_id' => $user?->id,
                'type' => 'note',
                'body' => ($outcome === 'completed' ? 'Kurs abgeschlossen' : 'Kurs abgebrochen').' am '.$leftOn->format('d.m.Y')
                    ." ({$participant->number}). Verbleibserhebung am {$followUp->format('d.m.Y')}.",
            ]);
        });
    }

    /** Verbleib sechs Monate nach Kursende erfasst: Wiedervorlage erledigt. */
    public function recordPlacement(Participant $participant, string $status, mixed $recordedOn, ?User $user = null): void
    {
        if (! array_key_exists($status, config('adk.placement_statuses'))) {
            throw ValidationException::withMessages(['placement_status' => 'Bitte wählen Sie einen Verbleib.']);
        }

        $recordedOn = CarbonImmutable::parse($recordedOn);
        $user ??= auth()->user();

        DB::transaction(function () use ($participant, $status, $recordedOn, $user) {
            $participant->update([
                'placement_status' => $status,
                'placement_recorded_on' => $recordedOn->toDateString(),
                'follow_up_on' => null,
            ]);

            // Wiedervorlage am Vorgang auf die nächste offene Verbleibserhebung, sonst keine.
            $lead = $participant->lead;
            $lead->next_action_at = $lead->participants()->whereNotNull('follow_up_on')->min('follow_up_on');
            $lead->save();

            Activity::create([
                'lead_id' => $lead->id,
                'user_id' => $user?->id,
                'type' => 'note',
                'body' => "Verbleib erfasst ({$participant->number}): ".config("adk.placement_statuses.{$status}").'.',
            ]);
        });
    }
}
