<?php

namespace App\Services\Microsoft;

use App\Models\Activity;
use App\Models\BlocklistEntry;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\User;
use App\Support\Normalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * „E-Mail schreiben“ am Vorgang: Versand aus dem eigenen Microsoft-365-Postfach, danach
 * Aktivität „E-Mail“ mit vollem Text im Verlauf, auf Wunsch Wiedervorlage, Eintrag im Protokoll
 * (ohne Text). Sperrliste und Bestätigung der Einwilligung werden vorher geprüft. Der Status bleibt.
 */
class LeadEmail
{
    public function __construct(private GraphMailer $mailer) {}

    /** Knopf „E-Mail schreiben“ mit Formular statt Hinweis auf „E-Mail-Konto“? */
    public static function connectedFor(?User $user): bool
    {
        return $user !== null && MicrosoftClient::isConfigured() && $user->mailConnection()->exists();
    }

    /** Vorschlag für „An“: Ansprechperson vor Betrieb. */
    public static function defaultRecipient(Lead $lead): ?string
    {
        return $lead->contact?->email ?? $lead->organization?->email;
    }

    /** Grund, warum keine E-Mail gesendet werden darf, oder null. */
    public static function blockReason(Lead $lead, ?string $to = null): ?string
    {
        if ($lead->isBlocked()) {
            return 'Der Vorgang steht auf der Sperrliste (Werbewiderspruch). Eine E-Mail ist nicht möglich.';
        }

        if (filled($to) && BlocklistEntry::matches(email: $to)) {
            return 'Die Adresse '.Normalizer::email($to).' steht auf der Sperrliste (Werbewiderspruch). Die E-Mail wird nicht gesendet.';
        }

        return null;
    }

    /** @return array<string, string> Platzhalter aus EmailTemplate::PLACEHOLDERS mit den Werten des Vorgangs */
    public static function replacements(Lead $lead, ?User $sender): array
    {
        $contact = $lead->contact;

        return [
            '{anrede}' => self::salutation($contact),
            '{vorname}' => (string) $contact?->first_name,
            '{nachname}' => (string) $contact?->last_name,
            '{firma}' => (string) $lead->organization?->name,
            '{absender}' => (string) ($sender?->mailConnection?->display_name ?: $sender?->name),
        ];
    }

    public static function render(?string $text, Lead $lead, ?User $sender): string
    {
        return strtr((string) $text, self::replacements($lead, $sender));
    }

    /** Briefanrede: „Sehr geehrte Frau Muster“, neutral „Guten Tag Alex Muster“ (z. B. „divers“), ohne Namen „Sehr geehrte Damen und Herren“. */
    public static function salutation(?Contact $contact): string
    {
        $lastName = trim((string) $contact?->last_name);

        if ($contact === null || $lastName === '') {
            return 'Sehr geehrte Damen und Herren';
        }

        return match (Str::lower(trim((string) $contact->salutation))) {
            'herr' => "Sehr geehrter Herr {$lastName}",
            'frau' => "Sehr geehrte Frau {$lastName}",
            default => implode(' ', array_filter(['Guten Tag', trim((string) $contact->first_name), $lastName])),
        };
    }

    /**
     * @param  array{email_template_id?: mixed, email_to?: ?string, email_subject?: ?string, email_text?: ?string, attach_template_file?: mixed, consent_confirmed?: mixed, next_action_at?: ?string, next_action_time?: ?string}  $data
     *
     * @throws RuntimeException mit einer Meldung für die Oberfläche; dann ist nichts gesendet
     */
    public function send(User $user, Lead $lead, array $data): Activity
    {
        if (! MicrosoftClient::isConfigured()) {
            throw new RuntimeException('E-Mail aus dem CRM ist noch nicht eingerichtet.');
        }

        $connection = $user->mailConnection;

        if ($connection === null) {
            throw new RuntimeException('Bitte verbinden Sie zuerst unter „E-Mail-Konto“ Ihr Microsoft-365-Postfach.');
        }

        if (! $lead->isOpen()) {
            throw new RuntimeException('Der Vorgang ist geschlossen.');
        }

        // § 7 UWG: Werbung per E-Mail nur mit Einwilligung, auch bei Betrieben.
        if (empty($data['consent_confirmed'])) {
            throw new RuntimeException('Bitte bestätigen Sie, dass die Person um diese E-Mail gebeten oder eingewilligt hat.');
        }

        $to = Normalizer::email($data['email_to'] ?? null);

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Bitte geben Sie eine gültige E-Mail-Adresse an.');
        }

        if ($reason = self::blockReason($lead, $to)) {
            throw new RuntimeException($reason);
        }

        // Platzhalter auch hier ersetzen, falls sie von Hand in Betreff oder Text stehen.
        $subject = trim(self::render($data['email_subject'] ?? '', $lead, $user));
        $text = trim(self::render($data['email_text'] ?? '', $lead, $user));

        if ($subject === '' || $text === '') {
            throw new RuntimeException('Betreff und Text dürfen nicht leer sein.');
        }

        $followUp = filled($data['next_action_at'] ?? null) ? Carbon::parse($data['next_action_at'])->startOfDay() : null;

        if ($followUp?->lt(today())) {
            throw new RuntimeException('Die Wiedervorlage darf nicht in der Vergangenheit liegen.');
        }

        $template = filled($data['email_template_id'] ?? null) ? EmailTemplate::find($data['email_template_id']) : null;
        $attachments = ! empty($data['attach_template_file']) && $template?->hasAttachment() ? [$this->templateAttachment($template)] : [];

        $reference = $this->mailer->sendMail($connection, $to, $subject, $text, $attachments);

        return DB::transaction(function () use ($user, $lead, $data, $to, $subject, $text, $followUp, $template, $attachments, $reference) {
            $followUpLine = null;

            if ($followUp) {
                $before = $lead->nextActionLabel() ?? 'keine';
                $lead->next_action_at = $followUp->toDateString();
                $lead->next_action_time = $data['next_action_time'] ?? null;
                $lead->save();
                $followUpLine = 'Wiedervorlage: '.$before.' → '.$lead->nextActionLabel();
            }

            $consent = 'Einwilligung: beim Senden bestätigt (Bitte oder Einwilligung der Person)';

            if ($lead->contact?->hasEmailConsent()) {
                $consent .= ', am Kontakt eingetragen seit '.$lead->contact->email_consent_at->format('d.m.Y');
            }

            // Der volle Text steht im Verlauf. Das Protokoll bekommt nur den Eintrag „E-Mail gesendet“ ohne Text.
            $activity = (new Activity([
                'lead_id' => $lead->id,
                'user_id' => $user->id,
                'type' => 'email',
                'external_id' => 'm365:'.$reference,
                'body' => implode("\n", array_filter([
                    'An: '.$to.' · Betreff: '.$subject,
                    $template ? 'Vorlage: '.$template->name : null,
                    $attachments ? 'Anhang: '.$attachments[0]['name'] : null,
                    $consent,
                    $followUpLine,
                ]))."\n\n".$text,
            ]))->disableLogging();
            $activity->save();

            activity('microsoft')->causedBy($user)->performedOn($lead)->event('email_sent')
                ->withProperties(array_filter([
                    'to' => $to,
                    'template' => $template?->name,
                    'attachment' => $attachments[0]['name'] ?? null,
                    'follow_up' => $followUp ? $lead->nextActionLabel() : null,
                ]))
                ->log('E-Mail über Microsoft 365 gesendet');

            return $activity;
        });
    }

    /** @return array{name: string, content_type: string, contents: string} */
    private function templateAttachment(EmailTemplate $template): array
    {
        $disk = Storage::disk(EmailTemplate::DISK);

        if (! $disk->exists($template->attachment_path)) {
            throw new RuntimeException('Die Datei der Vorlage „'.$template->name.'“ fehlt. Bitte unter Verwaltung → E-Mail-Vorlagen neu hochladen.');
        }

        return [
            'name' => $template->attachmentName(),
            'content_type' => $disk->mimeType($template->attachment_path) ?: 'application/octet-stream',
            'contents' => $disk->get($template->attachment_path),
        ];
    }
}
