<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\FundingCase;
use App\Models\FundingCaseStep;
use App\Models\FundingStep;
use App\Models\Lead;
use App\Models\User;
use App\Support\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stufe 3 · Förderweg: vom festen Interessenten bis zur bestätigten Einschreibung.
 *
 * Die Wiedervorlage des Förderfalls ist die Wiedervorlage des Vorgangs (leads.next_action_at).
 * Dadurch erscheinen fällige Förderfälle automatisch in „Heute“ und im Kalender.
 */
class FundingService
{
    /** Prüft, ob der Förderweg starten kann. Wirft ValidationException mit verständlichem Grund. */
    public function assertCanStart(Lead $lead, ?string $targetGroup = null): string
    {
        if ($targetGroup !== null) {
            $lead->target_group = $targetGroup;
        }

        $pathway = $lead->fundingPathway();

        if ($pathway === null) {
            throw ValidationException::withMessages([
                'target_group' => 'Bitte wählen Sie die Zielgruppe (A, B, C, D, E oder Selbstzahler), damit das CRM den passenden Förderweg kennt.',
            ]);
        }

        if ((config("adk.funding_pathways.{$pathway}.requires_health_consent") ?? false) && ! $lead->contact?->health_consent_at) {
            throw ValidationException::withMessages([
                'target_group' => 'Für Zielgruppe E (Arbeitsunfall) muss zuerst die Einwilligung zu Gesundheitsangaben am Kontakt eingetragen sein.',
            ]);
        }

        return $pathway;
    }

    public function start(Lead $lead, ?User $user = null, ?string $targetGroup = null): FundingCase
    {
        $pathway = $this->assertCanStart($lead, $targetGroup);
        $user ??= auth()->user();

        return DB::transaction(function () use ($lead, $pathway, $user) {
            $case = $lead->fundingCase()->first();

            if ($case) {
                $case->update(['pathway' => $pathway, 'state' => 'open', 'cancelled_at' => null, 'cancel_reason' => null]);
            } else {
                $case = $lead->fundingCase()->create(['pathway' => $pathway]);
            }

            $lead->status = 'handed_over';
            $lead->closed_at = null;
            $lead->next_action_at = CarbonImmutable::today();
            $lead->save();

            Activity::create([
                'lead_id' => $lead->id,
                'user_id' => $user?->id,
                'type' => 'funding_step',
                'body' => 'Förderweg gestartet: '.$case->pathwayLabel(),
            ]);

            return $case;
        });
    }

    /**
     * Schritt erledigen. $data: completed_on (Datum), result (done|rejected), party, note.
     * Danach Wiedervorlage nach der Frist des erledigten Schritts.
     */
    public function completeStep(FundingCase $case, FundingStep $step, array $data, ?User $user = null): FundingCaseStep
    {
        $user ??= auth()->user();

        if (! $case->isOpen()) {
            throw ValidationException::withMessages(['step' => 'Der Förderfall ist nicht mehr offen.']);
        }

        if ($step->pathway !== $case->pathway) {
            throw ValidationException::withMessages(['step' => 'Der Schritt gehört nicht zu diesem Förderweg.']);
        }

        if ($case->completedSteps()->where('funding_step_id', $step->id)->exists()) {
            throw ValidationException::withMessages(['step' => 'Dieser Schritt ist bereits erledigt.']);
        }

        $completedOn = CarbonImmutable::parse($data['completed_on'] ?? 'today')->startOfDay();

        if ($completedOn->isAfter(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['completed_on' => 'Das Datum darf nicht in der Zukunft liegen.']);
        }

        $result = $data['result'] ?? 'done';

        if (! array_key_exists($result, FundingCaseStep::RESULTS) || ($result === 'rejected' && ! $step->can_fail)) {
            throw ValidationException::withMessages(['result' => 'Dieser Schritt kann nur als erledigt eingetragen werden.']);
        }

        return DB::transaction(function () use ($case, $step, $data, $user, $completedOn, $result) {
            $record = $case->completedSteps()->create([
                'funding_step_id' => $step->id,
                'completed_on' => $completedOn,
                'result' => $result,
                'party' => $data['party'] ?? $step->default_party,
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'user_id' => $user?->id,
            ]);

            $lead = $case->lead;
            $lead->next_action_at = $result === 'rejected'
                ? CarbonImmutable::today()
                : ($case->currentStep() ? $this->followUp($step, $completedOn) : CarbonImmutable::today());
            $lead->save();

            Activity::create([
                'lead_id' => $lead->id,
                'user_id' => $user?->id,
                'type' => 'funding_step',
                'body' => trim($step->name.': '.$record->resultLabel().' am '.$completedOn->format('d.m.Y')
                    .($record->partyLabel() ? ' ('.$record->partyLabel().')' : '')
                    .($record->note ? "\n".$record->note : '')),
            ]);

            return $record;
        });
    }

    /** Eintrag zurücknehmen, z. B. bei einem Versehen. Wiedervorlage wird neu berechnet. */
    public function undoStep(FundingCaseStep $record, ?User $user = null): void
    {
        $case = $record->fundingCase;

        DB::transaction(function () use ($record, $case, $user) {
            $name = $record->step?->name;
            $record->delete();

            $last = $case->completedSteps()->with('step')->get()->last();
            $case->lead->next_action_at = $last && $last->step
                ? $this->followUp($last->step, CarbonImmutable::parse($last->completed_on))
                : CarbonImmutable::today();
            $case->lead->save();

            Activity::create([
                'lead_id' => $case->lead_id,
                'user_id' => ($user ?? auth()->user())?->id,
                'type' => 'funding_step',
                'body' => "Schritt zurückgenommen: {$name}",
            ]);
        });
    }

    /**
     * Einschreibung bestätigt: aus dem Förderfall wird ein Teilnehmer.
     * Die Teilnehmerakte folgt in Stufe 4. Der Vorgang fällt ab jetzt nicht mehr
     * unter die Löschfrist für Interessenten (contracted_at).
     */
    public function enroll(FundingCase $case, ?User $user = null): void
    {
        if (! $case->isOpen() || ! $case->allStepsDone()) {
            throw ValidationException::withMessages(['state' => 'Die Einschreibung kann erst bestätigt werden, wenn alle Schritte erledigt sind.']);
        }

        DB::transaction(function () use ($case, $user) {
            $case->update(['state' => 'enrolled', 'enrolled_at' => now()]);

            $lead = $case->lead;
            $from = $lead->status;
            $lead->status = 'enrolled';
            $lead->contracted_at ??= now();
            $lead->next_action_at = null;
            $lead->save();

            Activity::create([
                'lead_id' => $lead->id,
                'user_id' => ($user ?? auth()->user())?->id,
                'type' => 'status_change',
                'status_from' => $from,
                'status_to' => 'enrolled',
                'body' => 'Einschreibung vom Kostenträger bestätigt. Teilnehmerakte folgt (Stufe 4).',
            ]);
        });
    }

    /**
     * Förderweg abbrechen. $outcome: no_interest (Vorgang schließen) oder later (Wiedervorlage).
     */
    public function cancel(FundingCase $case, string $reason, string $outcome = 'no_interest', ?string $followUpAt = null, ?User $user = null): void
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Bitte geben Sie einen Grund an.']);
        }

        DB::transaction(function () use ($case, $reason, $outcome, $followUpAt, $user) {
            $case->update(['state' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);

            app(LeadStatusService::class)->apply($case->lead, $outcome === 'later' ? 'later' : 'no_interest', [
                'next_action_at' => $followUpAt,
                'note' => "Förderweg abgebrochen: {$reason}",
            ], $user);
        });
    }

    private function followUp(FundingStep $step, CarbonImmutable $from): CarbonImmutable
    {
        return $step->calendar_days
            ? $from->addDays($step->follow_up_days)
            : WorkingDays::add($from, $step->follow_up_days);
    }
}
