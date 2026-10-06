<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\BlocklistEntry;
use App\Models\Contact;
use App\Models\Lead;
use App\Services\Duplicates\DuplicateFinder;
use App\Support\Normalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Website-Eingang: Aus Kontaktformular bzw. Kursheft-Anforderung entstehen Kontakt (Privatperson),
 * Vorgang (Kanal Website-Formular, Status neu, Wiedervorlage heute) und eine Notiz mit der Anfrage.
 *
 * - Jeder Website-Vorgang („kontakt-17“, „kursheft-5“) wird nur einmal angelegt. Eine wiederholte
 *   Übertragung liefert die vorhandene Nummer und ändert nichts.
 * - Stehen Telefon oder E-Mail auf der Sperrliste, wird nichts angelegt.
 * - Rückruf erlaubt: Einwilligung in die Telefonansprache mit Nachweis (Zeitpunkt, gekürzte IP, Seite,
 *   Wortlaut). Nicht erlaubt: „Kein Anruf gewünscht“, das CRM sperrt dann Anrufe.
 * - Kursheft (erst nach Double-Opt-in übertragen): Einwilligung in die Kontaktaufnahme per E-Mail mit Nachweis.
 * - Danach Dublettenprüfung wie beim Anlegen im CRM.
 * - Im Protokoll stehen nur Website-Vorgang und Art, keine Namen oder Adressen.
 */
class WebsiteIntakeService
{
    /**
     * @param  array<string, mixed>  $data  geprüfte Angaben der Website (siehe WebsiteIntakeController)
     * @return array{crm_id: ?string, gesperrt: bool, neu: bool}
     */
    public function receive(array $data): array
    {
        $art = $data['art'];
        $ref = $art.'-'.(int) $data['vorgang_id'];

        if ($existing = Lead::where('intake_ref', $ref)->first()) {
            return $this->result($existing, neu: false);
        }

        if (BlocklistEntry::findMatch(phone: $data['telefon'] ?? null, email: $data['email'])) {
            activity('intake')
                ->event('blocked')
                ->withProperties(['ref' => $ref])
                ->log('Website-Eingang wegen Sperrliste verworfen');

            return ['crm_id' => null, 'gesperrt' => true, 'neu' => false];
        }

        try {
            [$lead, $contact] = DB::transaction(fn () => $this->create($art, $ref, $data));
        } catch (UniqueConstraintViolationException) {
            // Dieselbe Anfrage kam gleichzeitig zweimal an: Die andere Übertragung hat den Vorgang angelegt.
            return $this->result(Lead::where('intake_ref', $ref)->firstOrFail(), neu: false);
        }

        // Ähnliche Einträge (z. B. dieselbe Person mit früherer Anfrage): in die Dublettenprüfung.
        app(DuplicateFinder::class)->record($contact);

        activity('intake')
            ->event('received')
            ->performedOn($lead)
            ->withProperties(['ref' => $ref, 'art' => $art])
            ->log('Eingang über die Website');

        return $this->result($lead, neu: true);
    }

    /** @return array{0: Lead, 1: Contact} */
    private function create(string $art, string $ref, array $data): array
    {
        $kursheft = $art === 'kursheft';
        $at = $this->time($kursheft ? $data['bestaetigt'] : $data['erstellt']);
        $phoneAllowed = (bool) $data['tel_ok'];
        [$firstName, $lastName] = $this->splitName($data['name'] ?? null, $data['email']);

        $contact = Contact::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $data['email'],
            'phone_display' => $data['telefon'] ?? null,
            'is_private' => true,
            'phone_consent_at' => $phoneAllowed ? $at->toDateString() : null,
            'phone_consent_proof' => $phoneAllowed ? $this->phoneProof($art, $ref, $data) : null,
            'phone_refused' => ! $phoneAllowed,
            'email_consent_at' => $kursheft ? $at->toDateString() : null,
            'email_consent_proof' => $kursheft ? $this->emailProof($ref, $data) : null,
        ]);

        $lead = Lead::create([
            'contact_id' => $contact->id,
            'organization_id' => null,
            'target_group' => $this->targetGroup($art, $data),
            'channel' => 'website_form',
            'status' => 'new',
            'intake_ref' => $ref,
            'last_contact_at' => $at,
        ]);

        Activity::create([
            'lead_id' => $lead->id,
            'type' => 'note',
            'user_id' => null,
            'occurred_at' => $at,
            'body' => $this->summary($art, $ref, $data),
        ]);

        return [$lead, $contact];
    }

    /** Kontaktformular: Anliegen; Kursheft: Finanzierung. Ohne Angabe „Zuordnung offen“. */
    private function targetGroup(string $art, array $data): string
    {
        return $this->option($art, $data)['target_group'] ?? 'open';
    }

    /** @return array{label: string, target_group: string}|null */
    private function option(string $art, array $data): ?array
    {
        $field = $art === 'kursheft' ? 'finanzierung' : 'anliegen';

        return config("adk.website_intake.{$field}")[$data[$field] ?? ''] ?? null;
    }

    /** Notiz am Vorgang: was angefragt wurde, ob angerufen werden darf, die Nachricht. */
    private function summary(string $art, string $ref, array $data): string
    {
        $label = $this->option($art, $data)['label'] ?? 'keine Angabe';

        $lines = $art === 'kursheft'
            ? ['Kursheft über die Website angefordert (bestätigt)', "Finanzierung: {$label}"]
            : ['Eingang über das Website-Kontaktformular', "Anliegen: {$label}"];

        $lines[] = 'Rückruf erlaubt: '.($data['tel_ok'] ? 'ja' : 'nein (bitte per E-Mail antworten)');

        if ($art === 'kontakt' && filled($data['nachricht'] ?? null)) {
            $lines[] = "Nachricht:\n".trim($data['nachricht']);
        }

        if (filled($data['seite'] ?? null)) {
            $lines[] = 'Seite: '.$data['seite'];
        }

        $lines[] = "Website-Vorgang: {$ref}";

        return implode("\n", $lines);
    }

    /** Nachweis der Einwilligung in Anrufe: wann, gekürzte IP, Seite, Wortlaut der Checkbox. */
    private function phoneProof(string $art, string $ref, array $data): string
    {
        if ($art === 'kursheft') {
            $parts = [
                'Kursheft-Anforderung mit Bestätigung (Double-Opt-in) am '.$this->when($data['bestaetigt']).$this->ip($data['bestaetigt_ip'] ?? null),
                'angefordert am '.$this->when($data['angefragt']).$this->ip($data['angefragt_ip'] ?? null),
            ];
        } else {
            $parts = ['Website-Kontaktformular, abgesendet am '.$this->when($data['erstellt'])];

            if (filled($data['ip_gekuerzt'] ?? null)) {
                $parts[] = 'gekürzte IP '.$data['ip_gekuerzt'];
            }
        }

        if (filled($data['seite'] ?? null)) {
            $parts[] = 'Seite '.$data['seite'];
        }

        return implode(', ', $parts).'.'.$this->wording($data['tel_ok_text'] ?? null)." Website-Vorgang {$ref}.";
    }

    /** Nachweis der Einwilligung in E-Mails (Double-Opt-in der Kursheft-Anforderung). */
    private function emailProof(string $ref, array $data): string
    {
        $text = 'Kursheft angefordert am '.$this->when($data['angefragt']).$this->ip($data['angefragt_ip'] ?? null)
            .', bestätigt am '.$this->when($data['bestaetigt']).$this->ip($data['bestaetigt_ip'] ?? null)
            .' über den Link in der Bestätigungsmail.'
            .$this->wording($data['kontakt_text'] ?? null);

        if (filled($data['seite'] ?? null)) {
            $text .= ' Seite '.$data['seite'].'.';
        }

        return $text." Website-Vorgang {$ref}.";
    }

    private function wording(?string $text): string
    {
        return filled($text) ? ' Wortlaut: „'.trim($text).'“.' : '';
    }

    private function ip(?string $ip): string
    {
        return filled($ip) ? " (gekürzte IP {$ip})" : '';
    }

    /** „06.10.2026 um 16:33 Uhr“ */
    private function when(string $value): string
    {
        $time = $this->time($value);

        return $time->format('d.m.Y').' um '.$time->format('H:i').' Uhr';
    }

    /** Zeitangaben der Website sind Ortszeit (Europe/Berlin), wie die Zeitzone des CRM. */
    private function time(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, config('app.timezone'));
    }

    /** „Max Muster“ → Vorname Max, Nachname Muster. Ein Wort → Nachname. Ohne Namen → E-Mail als Nachname. */
    private function splitName(?string $name, string $email): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));

        if ($name === '') {
            return [null, Normalizer::email($email)];
        }

        $position = mb_strrpos($name, ' ');

        return $position === false
            ? [null, $name]
            : [mb_substr($name, 0, $position), mb_substr($name, $position + 1)];
    }

    /** @return array{crm_id: string, gesperrt: bool, neu: bool} */
    private function result(Lead $lead, bool $neu): array
    {
        return ['crm_id' => "V-{$lead->id}", 'gesperrt' => false, 'neu' => $neu];
    }
}
