<?php

namespace App\Services\Duplicates;

use App\Models\Contact;
use App\Models\DuplicateCandidate;
use App\Models\ImportLog;
use App\Models\Lead;
use App\Models\Organization;
use App\Support\Adk;
use App\Support\Normalizer;
use App\Support\Phone;
use App\Support\Similarity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Dublettenprüfung gegen den ganzen Bestand: alle Organisationen und Kontakte, egal ob
 * Akquise, Förderfall, Teilnehmer oder geschlossen.
 *
 * Vorgehen wie in der Praxis üblich: erst vereinheitlichen (Rechtsform, Umlaute, Schreibweisen
 * von Telefon, Website, Straße), dann Kandidaten über indizierte Merkmale vorauswählen
 * (Telefon, E-Mail, Domain, PLZ, Ort, Namensanfang), dann jeden Kandidaten bewerten.
 *
 * - sicher (100): gleiche Telefonnummer, E-Mail-Adresse oder Website, gleicher Name mit gleicher PLZ
 * - Verdacht (ab config adk.duplicates.suspect_score): sehr ähnlicher Name am selben Ort (Tippfehler, Zusatz im Namen),
 *   gleiche Anschrift,
 *   E-Mail-Domain passt zur Website, gleicher Name ohne Ortsangabe
 */
class DuplicateFinder
{
    /**
     * @param  array<string, mixed>  $data  name, street, postal_code, city, phone|phone_display|phone_e164, email, website,
     *                                      contact_phone, contact_email
     * @return Collection<int, DuplicateMatch>
     */
    public function organizationMatches(array $data, ?int $ignoreId = null): Collection
    {
        $name = $data['name'] ?? null;
        $nameKey = Normalizer::companyName($name);
        $postalCode = Normalizer::postalCode($data['postal_code'] ?? null);
        $city = $this->lower($data['city'] ?? null);
        $street = Normalizer::street($data['street'] ?? null);
        $phones = $this->phones([$data['phone_e164'] ?? null, $data['phone'] ?? null, $data['phone_display'] ?? null, $data['contact_phone'] ?? null]);
        $emails = $this->emails([$data['email'] ?? null, $data['contact_email'] ?? null]);
        $domain = $this->websiteDomain($data['website'] ?? null);
        $emailDomains = $this->companyEmailDomains($emails);

        if (! $nameKey && ! $phones && ! $emails && ! $domain) {
            return collect();
        }

        $candidates = Organization::query()
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->where(function (Builder $q) use ($phones, $emails, $domain, $emailDomains, $postalCode, $city, $nameKey) {
                if ($phones) {
                    $q->orWhereIn('phone_e164', $phones)->orWhereHas('contacts', fn (Builder $c) => $c->whereIn('phone_e164', $phones));
                }

                if ($emails) {
                    $q->orWhereIn('email', $emails)->orWhereHas('contacts', fn (Builder $c) => $c->whereIn('email', $emails));
                }

                if ($domain || $emailDomains) {
                    $q->orWhereIn('website_domain', array_filter([$domain, ...$emailDomains]));
                }

                if ($domain) {
                    $q->orWhere('email', 'like', '%@'.$domain);
                }

                if ($postalCode) {
                    $q->orWhere('postal_code', $postalCode);
                }

                if ($city) {
                    $q->orWhereRaw('LOWER(city) = ?', [$city]);
                }

                if ($nameKey && strlen($nameKey) >= 4) {
                    $q->orWhere('name_normalized', 'like', substr($nameKey, 0, 4).'%');
                }
            })
            ->with('contacts:id,organization_id,phone_e164,email')
            ->limit(2000)
            ->get();

        $input = compact('name', 'nameKey', 'postalCode', 'city', 'street', 'phones', 'emails', 'domain', 'emailDomains');

        return $candidates
            ->map(fn (Organization $organization) => $this->scoreOrganization($organization, $input))
            ->filter()
            ->sortByDesc(fn (DuplicateMatch $match) => $match->score)
            ->values();
    }

    /** @param array<string, mixed> $in */
    private function scoreOrganization(Organization $organization, array $in): ?DuplicateMatch
    {
        $reasons = [];
        $certain = false;
        $score = 0;
        $contactPhones = $organization->contacts->pluck('phone_e164')->filter()->all();
        $contactEmails = $organization->contacts->pluck('email')->filter()->map(fn ($e) => Normalizer::email($e))->all();

        if ($in['phones'] && in_array($organization->phone_e164, $in['phones'], true)) {
            [$certain, $reasons[]] = [true, 'gleiche Telefonnummer'];
        } elseif ($in['phones'] && array_intersect($in['phones'], $contactPhones)) {
            [$certain, $reasons[]] = [true, 'gleiche Telefonnummer wie ein Kontakt dort'];
        }

        if ($in['emails'] && in_array(Normalizer::email($organization->email), $in['emails'], true)) {
            [$certain, $reasons[]] = [true, 'gleiche E-Mail-Adresse'];
        } elseif ($in['emails'] && array_intersect($in['emails'], $contactEmails)) {
            [$certain, $reasons[]] = [true, 'gleiche E-Mail-Adresse wie ein Kontakt dort'];
        }

        if ($in['domain'] && $in['domain'] === $organization->website_domain) {
            [$certain, $reasons[]] = [true, 'gleiche Website'];
        }

        $sameName = $in['nameKey'] && $in['nameKey'] === $organization->name_normalized;
        $samePostalCode = $in['postalCode'] && $in['postalCode'] === $organization->postal_code;
        $sameCity = $in['city'] && $in['city'] === $this->lower($organization->city);
        $similarity = $sameName ? 1.0 : Similarity::companyNames($in['name'], $organization->name);

        if ($sameName && $samePostalCode) {
            [$certain, $reasons[]] = [true, 'gleicher Firmenname und gleiche PLZ'];
        } elseif ($sameName && $sameCity) {
            $score = max($score, 90);
            $reasons[] = 'gleicher Firmenname am selben Ort';
        } elseif ($sameName && (! $in['postalCode'] || ! $organization->postal_code) && (! $in['city'] || ! $organization->city)) {
            $score = max($score, 80);
            $reasons[] = 'gleicher Firmenname, Ort fehlt';
        } elseif (! $sameName && $similarity >= 0.95 && $this->coreLength($in['name'], $organization->name) >= 8 && ($samePostalCode || $sameCity)) {
            // Tippfehler in längeren Namen („Schreinerei Weber“ / „Webber“). Kurze Familiennamen
            // mit einem Buchstaben Unterschied (Brunner / Brenner) sind meist verschiedene Firmen.
            $score = max($score, 85);
            $reasons[] = 'sehr ähnlicher Firmenname am selben Ort';
        } elseif (! $sameName && $samePostalCode && Similarity::companyContains($in['name'], $organization->name)) {
            $score = max($score, 80);
            $reasons[] = 'Firmenname mit Zusatz, gleiche PLZ';
        }

        if ($in['street'] && $samePostalCode && $in['street'] === Normalizer::street($organization->street) && $similarity >= 0.5 && ! $certain) {
            $score = max($score, 82);
            $reasons[] = 'gleiche Anschrift';
        }

        $organizationEmailDomain = $this->companyEmailDomains($this->emails([$organization->email]))[0] ?? null;

        if (! $certain && (
            ($in['emailDomains'] && in_array($organization->website_domain, $in['emailDomains'], true))
            || ($in['domain'] && $in['domain'] === $organizationEmailDomain)
        )) {
            $score = max($score, 88);
            $reasons[] = 'E-Mail-Domain passt zur Website';
        }

        if ($certain) {
            return new DuplicateMatch($organization, 100, true, array_values(array_unique($reasons)));
        }

        return $score >= config('adk.duplicates.suspect_score')
            ? new DuplicateMatch($organization, $score, false, array_values(array_unique($reasons)))
            : null;
    }

    /**
     * @param  array<string, mixed>  $data  first_name, last_name, email, phone|phone_display, organization_id, is_private
     * @return Collection<int, DuplicateMatch>
     */
    public function contactMatches(array $data, ?int $ignoreId = null): Collection
    {
        $nameKey = Normalizer::personName($data['first_name'] ?? null, $data['last_name'] ?? null);
        $lastName = $this->lower($data['last_name'] ?? null);
        $phones = $this->phones([$data['phone_e164'] ?? null, $data['phone'] ?? null, $data['phone_display'] ?? null]);
        $emails = $this->emails([$data['email'] ?? null]);
        $organizationId = $data['organization_id'] ?? null;
        $isPrivate = (bool) ($data['is_private'] ?? false);

        if (! $lastName && ! $phones && ! $emails) {
            return collect();
        }

        return Contact::query()
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->where(function (Builder $q) use ($phones, $emails, $lastName) {
                if ($phones) {
                    $q->orWhereIn('phone_e164', $phones);
                }

                if ($emails) {
                    $q->orWhereIn('email', $emails);
                }

                if ($lastName) {
                    $q->orWhereRaw('LOWER(last_name) = ?', [$lastName]);
                }
            })
            ->limit(500)
            ->get()
            ->map(function (Contact $contact) use ($nameKey, $phones, $emails, $organizationId, $isPrivate) {
                $reasons = [];
                $certain = false;
                $score = 0;
                $sameOrganization = $organizationId && (int) $organizationId === (int) $contact->organization_id;
                $sameName = $nameKey && $nameKey === Normalizer::personName($contact->first_name, $contact->last_name);

                if ($emails && in_array(Normalizer::email($contact->email), $emails, true)) {
                    [$certain, $reasons[]] = [true, 'gleiche E-Mail-Adresse'];
                }

                if ($phones && in_array($contact->phone_e164, $phones, true)) {
                    if ($isPrivate || $contact->is_private) {
                        [$certain, $reasons[]] = [true, 'gleiche Telefonnummer'];
                    } elseif (! $sameOrganization) {
                        // Gleiche Zentrale im selben Betrieb ist normal, in einem anderen Betrieb auffällig.
                        $score = max($score, 80);
                        $reasons[] = 'gleiche Telefonnummer';
                    }
                }

                if ($sameName && $sameOrganization) {
                    [$certain, $reasons[]] = [true, 'gleicher Name im selben Betrieb'];
                } elseif ($sameName && $isPrivate && $contact->is_private) {
                    $score = max($score, 80);
                    $reasons[] = 'gleicher Name (Privatperson)';
                }

                if ($certain) {
                    return new DuplicateMatch($contact, 100, true, array_values(array_unique($reasons)));
                }

                return $score >= config('adk.duplicates.suspect_score')
                    ? new DuplicateMatch($contact, $score, false, array_values(array_unique($reasons)))
                    : null;
            })
            ->filter()
            ->sortByDesc(fn (DuplicateMatch $match) => $match->score)
            ->values();
    }

    /**
     * Verdachtsfälle für einen angelegten Datensatz speichern. Bereits entschiedene Paare
     * (in beide Richtungen) werden nicht erneut gemeldet.
     */
    public function record(Organization|Contact $subject, ?ImportLog $log = null): int
    {
        $type = $subject instanceof Organization ? 'organization' : 'contact';
        $matches = $subject instanceof Organization
            ? $this->organizationMatches($this->organizationData($subject), $subject->id)
            : $this->contactMatches($subject->only(['first_name', 'last_name', 'email', 'phone_e164', 'organization_id', 'is_private']), $subject->id);

        $created = 0;

        foreach ($matches as $match) {
            $created += $this->storeCandidate($type, $subject->id, $match, $log);
        }

        return $created;
    }

    /** Ganzen Bestand prüfen (z. B. nach früheren Importen). Der jüngere Datensatz wird zum Prüffall. */
    public function scanAll(): int
    {
        $created = 0;

        Organization::query()->with('contacts')->orderBy('id')->each(function (Organization $organization) use (&$created) {
            $matches = $this->organizationMatches($this->organizationData($organization), $organization->id)
                ->filter(fn (DuplicateMatch $match) => $match->record->getKey() < $organization->id);

            foreach ($matches as $match) {
                $created += $this->storeCandidate('organization', $organization->id, $match);
            }
        });

        Contact::query()->orderBy('id')->each(function (Contact $contact) use (&$created) {
            $matches = $this->contactMatches($contact->only(['first_name', 'last_name', 'email', 'phone_e164', 'organization_id', 'is_private']), $contact->id)
                ->filter(fn (DuplicateMatch $match) => $match->record->getKey() < $contact->id);

            foreach ($matches as $match) {
                $created += $this->storeCandidate('contact', $contact->id, $match);
            }
        });

        return $created;
    }

    private function storeCandidate(string $type, int $subjectId, DuplicateMatch $match, ?ImportLog $log = null): int
    {
        $exists = DuplicateCandidate::where('type', $type)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $p) => $p->where('subject_id', $subjectId)->where('match_id', $match->record->getKey()))
                ->orWhere(fn (Builder $p) => $p->where('subject_id', $match->record->getKey())->where('match_id', $subjectId)))
            ->exists();

        if ($exists) {
            return 0;
        }

        DuplicateCandidate::create([
            'type' => $type,
            'subject_id' => $subjectId,
            'match_id' => $match->record->getKey(),
            'score' => $match->score,
            'reasons' => $match->reasons,
            'import_log_id' => $log?->id,
        ]);

        return 1;
    }

    /** @return array<string, mixed> */
    public function organizationData(Organization $organization): array
    {
        $contact = $organization->contacts->first();

        return [
            'name' => $organization->name,
            'street' => $organization->street,
            'postal_code' => $organization->postal_code,
            'city' => $organization->city,
            'phone_e164' => $organization->phone_e164,
            'email' => $organization->email,
            'website' => $organization->website,
            'contact_phone' => $contact?->phone_e164,
            'contact_email' => $contact?->email,
        ];
    }

    /** Kurzbeschreibung mit Phase, z. B. „Muster GmbH, 55116 Mainz · Förderfall“. */
    public static function describe(Model $record): string
    {
        if ($record instanceof Organization) {
            $place = trim(($record->postal_code ?? '').' '.($record->city ?? ''));

            return $record->name.($place ? ", {$place}" : '').' · '.self::phase($record->leads()->with('participants')->get());
        }

        if ($record instanceof Contact) {
            $leads = $record->leads()->with('participants')->get();
            $phase = $record->participants()->exists() ? 'Teilnehmer/in' : self::phase($leads);

            return $record->fullName().($record->organization ? ' ('.$record->organization->name.')' : '').' · '.$phase;
        }

        return (string) $record->getKey();
    }

    /** @param Collection<int, Lead> $leads */
    public static function phase(Collection $leads): string
    {
        if ($leads->isEmpty()) {
            return 'ohne Vorgang';
        }

        if ($leads->contains(fn (Lead $lead) => $lead->status === 'enrolled' || $lead->participants->isNotEmpty())) {
            return 'Teilnehmer';
        }

        if ($leads->contains(fn (Lead $lead) => $lead->isOpen() && $lead->status === 'handed_over')) {
            return 'Förderfall';
        }

        if ($open = $leads->first(fn (Lead $lead) => $lead->isOpen())) {
            return 'Akquise: '.Adk::statusLabel($open->status);
        }

        return 'geschlossen: '.Adk::statusLabel($leads->sortByDesc('closed_at')->first()->status);
    }

    /** @return list<string> */
    private function phones(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($value) => Phone::normalize($value), $values))));
    }

    /** @return list<string> */
    private function emails(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? Normalizer::email($value) : null, $values))));
    }

    private function websiteDomain(?string $website): ?string
    {
        $domain = Normalizer::domain($website);

        return $domain && ! in_array($domain, config('adk.duplicates.generic_website_domains'), true) ? $domain : null;
    }

    /** @return list<string> Domains der E-Mail-Adressen ohne Freemail-Anbieter */
    private function companyEmailDomains(array $emails): array
    {
        return array_values(array_unique(array_filter(array_map(function (string $email) {
            $domain = substr(strrchr($email, '@') ?: '', 1);

            return $domain && ! in_array($domain, config('adk.duplicates.generic_email_domains'), true) ? $domain : null;
        }, $emails))));
    }

    private function coreLength(?string $a, ?string $b): int
    {
        return min(mb_strlen((string) Similarity::companyCore($a)), mb_strlen((string) Similarity::companyCore($b)));
    }

    private function lower(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }
}
