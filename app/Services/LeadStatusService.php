<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Appointment;
use App\Models\BlocklistEntry;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\User;
use App\Support\Adk;
use App\Support\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Setzt den Status eines Vorgangs nach den Regeln aus config('adk.statuses').
 *
 * $data kann enthalten:
 *  - note: string                     Freitext zur Aktivität
 *  - next_action_at: date             Wiedervorlage (Pflicht bei follow_up = required)
 *  - appointment: [date, time, type, user_id]   bei follow_up = appointment
 *  - close_reason: string             bei requires_reason (Datensatz falsch)
 *  - confirmed: bool                  Sicherheitsabfrage bei Werbewiderspruch
 *  - contact: [last_name, first_name, email]  Empfänger, falls „Unterlagen versendet“ ohne Kontakt
 */
class LeadStatusService
{
    public function apply(Lead $lead, string $status, array $data = [], ?User $user = null, bool $asCall = false): StatusResult
    {
        $definition = Adk::status($status);
        $user ??= auth()->user();
        $today = CarbonImmutable::today();
        $result = new StatusResult;

        if ($asCall && $lead->callBlockReason() !== null && ! $definition['closes']) {
            throw ValidationException::withMessages(['status' => $lead->callBlockReason()]);
        }

        if (($definition['blocklist'] ?? false) && ! ($data['confirmed'] ?? false)) {
            throw ValidationException::withMessages(['confirmed' => 'Bitte bestätigen Sie den Werbewiderspruch.']);
        }

        $closeReason = null;

        if ($definition['requires_reason'] ?? false) {
            $closeReason = $data['close_reason'] ?? null;

            if (! array_key_exists((string) $closeReason, config('adk.wrong_data_reasons'))) {
                throw ValidationException::withMessages(['close_reason' => 'Bitte wählen Sie einen Grund.']);
            }
        }

        $nextActionAt = match ($definition['follow_up']) {
            'today' => $today,
            'working_days' => WorkingDays::add($today, $definition['days']),
            'required' => $this->requiredDate($data['next_action_at'] ?? null, $today),
            'appointment' => $this->appointmentStart($data['appointment'] ?? [])->startOfDay(),
            default => null,
        };

        if ($status === 'documents_sent' && $lead->contact === null) {
            $this->validateRecipient($data['contact'] ?? []);
        }

        // Übergeben an Förderweg: Zielgruppe muss einen Förderweg haben (Stufe 3).
        if ($status === 'handed_over') {
            app(FundingService::class)->assertCanStart($lead, $data['target_group'] ?? null);
        }

        $from = $lead->status;

        DB::transaction(function () use ($lead, $status, $definition, $data, $user, $asCall, $today, $nextActionAt, $closeReason, $from, $result) {
            if ($status === 'not_reached') {
                $lead->call_attempts++;
                $result->suggestRest = $lead->call_attempts >= config('adk.not_reached_rest_after');
            }

            if ($status === 'documents_sent') {
                $contact = $lead->contact ?? $this->createRecipient($lead, $data['contact']);

                if ($contact->privacy_notice_sent_at === null) {
                    $contact->privacy_notice_sent_at = $today;
                    $contact->save();
                }
            }

            if ($definition['follow_up'] === 'appointment') {
                $result->appointment = $this->createAppointment($lead, $data['appointment'], $user);
            }

            if ($definition['blocklist'] ?? false) {
                $result->blocklistEntries = $this->addToBlocklist($lead, $user);
            }

            $lead->status = $status;
            $lead->next_action_at = $nextActionAt;
            $lead->close_reason = $closeReason;
            $lead->closed_at = $definition['closes'] ? now() : null;
            $lead->save();

            if ($asCall && $lead->contact?->is_private && $lead->contact->phone_consent_at) {
                $lead->contact->forceFill(['phone_consent_last_used_at' => $today])->save();
            }

            $result->activity = Activity::create([
                'lead_id' => $lead->id,
                'user_id' => $user?->id,
                'type' => $asCall ? 'call' : 'status_change',
                'outcome' => $status,
                'status_from' => $from,
                'status_to' => $status,
                'body' => $this->activityBody($data, $closeReason),
            ]);

            if ($status === 'handed_over') {
                $result->fundingCase = app(FundingService::class)->start($lead, $user);
            }
        });

        return $result;
    }

    /**
     * Taste 0: Merkmal Cross-Selling JB Design umschalten. Ändert den Status nicht.
     */
    public function toggleCrossSelling(Lead $lead, ?string $followUpAt = null, ?string $note = null, ?User $user = null): void
    {
        $enable = ! $lead->cross_selling;
        $date = $enable ? $this->requiredDate($followUpAt, CarbonImmutable::today()) : null;

        DB::transaction(function () use ($lead, $enable, $date, $note, $user) {
            $lead->cross_selling = $enable;
            $lead->cross_selling_follow_up_at = $date;
            $lead->save();

            Activity::create([
                'lead_id' => $lead->id,
                'user_id' => ($user ?? auth()->user())?->id,
                'type' => 'note',
                'body' => trim(($enable
                    ? 'Cross-Selling JB Design gesetzt, Wiedervorlage '.$date->format('d.m.Y')
                    : 'Cross-Selling JB Design entfernt').($note ? "\n".$note : '')),
            ]);
        });
    }

    /**
     * Nach mehreren erfolglosen Versuchen: Vorgang ruhen lassen.
     */
    public function rest(Lead $lead, ?User $user = null): void
    {
        $until = CarbonImmutable::today()->addMonths(config('adk.not_reached_rest_months'));

        $lead->next_action_at = $until;
        $lead->save();

        Activity::create([
            'lead_id' => $lead->id,
            'user_id' => ($user ?? auth()->user())?->id,
            'type' => 'note',
            'body' => "Vorgang ruht nach {$lead->call_attempts} Versuchen, Wiedervorlage {$until->format('d.m.Y')}",
        ]);
    }

    private function requiredDate(mixed $value, CarbonImmutable $today): CarbonImmutable
    {
        if (blank($value)) {
            throw ValidationException::withMessages(['next_action_at' => 'Bitte geben Sie ein Wiedervorlagedatum an.']);
        }

        $date = CarbonImmutable::parse($value)->startOfDay();

        if ($date->lt($today)) {
            throw ValidationException::withMessages(['next_action_at' => 'Das Wiedervorlagedatum darf nicht in der Vergangenheit liegen.']);
        }

        return $date;
    }

    private function appointmentStart(array $appointment): CarbonImmutable
    {
        $errors = [];

        if (blank($appointment['date'] ?? null)) {
            $errors['appointment.date'] = 'Bitte geben Sie das Datum des Termins an.';
        }

        if (blank($appointment['time'] ?? null)) {
            $errors['appointment.time'] = 'Bitte geben Sie die Uhrzeit des Termins an.';
        }

        if (! array_key_exists((string) ($appointment['type'] ?? ''), config('adk.appointment_types'))) {
            $errors['appointment.type'] = 'Bitte wählen Sie die Art des Termins.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $start = CarbonImmutable::parse(CarbonImmutable::parse($appointment['date'])->format('Y-m-d').' '.$appointment['time']);

        if ($start->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['appointment.date' => 'Der Termin darf nicht in der Vergangenheit liegen.']);
        }

        return $start;
    }

    private function createAppointment(Lead $lead, array $data, ?User $user): Appointment
    {
        return Appointment::create([
            'lead_id' => $lead->id,
            'user_id' => $data['user_id'] ?? $lead->assigned_to ?? $user?->id,
            'starts_at' => $this->appointmentStart($data),
            'type' => $data['type'],
            'notes' => $data['notes'] ?? null,
        ]);
    }

    private function validateRecipient(array $contact): void
    {
        if (blank($contact['last_name'] ?? null)) {
            throw ValidationException::withMessages([
                'contact.last_name' => 'Für den Vorgang ist kein Kontakt hinterlegt. Bitte geben Sie an, an wen die Unterlagen gingen.',
            ]);
        }
    }

    private function createRecipient(Lead $lead, array $data): Contact
    {
        $contact = Contact::create([
            'organization_id' => $lead->organization_id,
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'],
            'email' => $data['email'] ?? null,
            'is_private' => $lead->organization_id === null,
        ]);

        $lead->contact()->associate($contact);

        return $contact;
    }

    /** @return list<BlocklistEntry> */
    private function addToBlocklist(Lead $lead, ?User $user): array
    {
        $reason = "Werbewiderspruch, Vorgang {$lead->id}";
        $entries = [];
        $organization = $lead->organization;
        $contact = $lead->contact;

        if ($organization) {
            $entries[] = BlocklistEntry::create([
                'phone_e164' => $organization->phone_e164,
                'email' => $organization->email,
                'company_name' => $organization->name,
                'postal_code' => $organization->postal_code,
                'reason' => $reason,
                'created_by' => $user?->id,
            ]);
        }

        if ($contact && ($contact->phone_e164 || $contact->email)) {
            $entries[] = BlocklistEntry::create([
                'phone_e164' => $contact->phone_e164,
                'email' => $contact->email,
                'reason' => $reason,
                'created_by' => $user?->id,
            ]);
        }

        return $entries;
    }

    private function activityBody(array $data, ?string $closeReason): ?string
    {
        $parts = array_filter([
            $closeReason ? 'Grund: '.config("adk.wrong_data_reasons.{$closeReason}") : null,
            filled($data['note'] ?? null) ? trim($data['note']) : null,
        ]);

        return $parts ? implode("\n", $parts) : null;
    }
}
