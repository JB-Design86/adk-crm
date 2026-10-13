<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Document;
use App\Models\FundingCase;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Participant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Arzt- und Zahnarztpraxen aus den Leadlisten aussortieren (Auftrag Janosch, 13.10.2026): Sie dürfen laut
 * Berufsordnung kaum werben und passen nicht zum Projekt. Der übrige Gesundheitsbereich bleibt ausdrücklich,
 * vor allem Pflegedienste (wichtige Zielgruppe, dürfen werben), dazu Therapeuten, Kliniken, Apotheken, Optiker.
 * Im Zweifel bleibt ein Betrieb: Steht „Pflege“, eine Therapie oder eine Klinik im Namen, wird er nicht gelöscht.
 *
 * Erfasst nur unbearbeitete Vorgänge aus Importen: Status „Neu“, offen, ohne Anrufversuch, Aktivität, Termin,
 * Dokument, Teilnehmerakte oder Förderfall. Gelöscht wird endgültig über den RecordPurger, im Protokoll nur Anzahlen.
 */
class DoctorLeadCleanup
{
    /** Kein Gesundheitswesen, obwohl ein Treffer darin steckt (Fahrradklinik, Praxisbedarf …). Wird vor dem Prüfen entfernt. */
    private const NOT_HEALTH = '/(fahrrad|rad|handy|auto|kfz|computer|pc|laptop|smartphone|tablet|uhren|schuh|puppen|teppich|baum|reifen|moebel|pflanzen)klinik|praxis(boerse|bedarf|software|einrichtung)/u';

    /** Pflege und Betreuung: bleibt immer, auch wenn zusätzlich ein Arzt-Treffer im Namen steht. */
    private const CARE = '/pflege|sozialstation|altenheim|seniorenheim|seniorenresidenz|seniorenzentrum|seniorenbetreuung|betreutes wohnen|heimbeatmung|diakonie|caritas/u';

    /** Gesundheitsnah, darf werben: Tiermedizin, Handel und Handwerk, Therapeuten, Kliniken. Bleibt. */
    private const OTHER_HEALTH = '/tierarzt|tieraerzt|tierklinik|tierheil|tiermedizin|tierphysio|tierpraxis|tierther|veterinaer|med\.?\s?vet\b|apothek|augenoptik|optiker|\boptik\b|hoerakust|hoergeraet|sanitaetshaus|orthopaedietechn|orthopaedieschuh|medizintechn|medizinprodukt|pharma|physio|ergother|logopaed|heilprakt|naturheil|psychotherap|psycholog|osteopath|chiropr|podolog|akupunkt|hebamme|geburtshaus|klinik|krankenhaus|hospiz|hospital(?!ity)|\breha\b|rehabilit|therapie|therapeut|gesundheitszentrum/u';

    /** Andere „Praxen“ und Berufe ohne Arzt: Kanzlei, Beratung, Kosmetik, Massage … Bleibt. */
    private const NOT_DOCTOR = '/anwalt|kanzlei|steuer|notar|beratung|coaching|kosmetik|beauty|massage|tattoo|yoga|fitness|ernaehrung|tier|hund|katze|pferd|design|architekt|immobil/u';

    /** Arzt oder Zahnarzt („praxis“ allein nur, wenn nichts aus CARE, OTHER_HEALTH oder NOT_DOCTOR passt). */
    private const DOCTOR = '/zahnarzt|zahnaerzt|arzt|aerzt|zahnmedizin|zahnheilk|kieferorthop|kieferchirurg|oralchirurg|implantolog|orthopaed|\bmvz\b|\bhno\b|\bdr\.?\s?med\b|allgemeinmedizin|dermatolog|gynaekolog|urolog|neurolog|kardiolog|radiolog|nuklearmedizin|onkolog|internist|anaesthes|chirurg|psychiat|praxis/u';

    /** WZ 2008: 86.21 Allgemeinarzt-, 86.22 Facharzt-, 86.23 Zahnarztpraxen. */
    private const DOCTOR_WZ = ['8621', '8622', '8623'];

    public function __construct(private RecordPurger $purger) {}

    /**
     * 'doctor' (wird gelöscht), 'kept' (sieht nach Arzt aus, bleibt aber wegen Pflege, Therapie, Klinik, Tiermedizin …)
     * oder null (kein Arzt-Treffer), dazu das entscheidende Wort. Im Zweifel bleibt der Betrieb.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function classify(?string $name, ?string $industry = null, ?string $wzCode = null): array
    {
        $text = strtr(mb_strtolower(trim($name.' '.$industry)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $text = preg_replace(self::NOT_HEALTH, ' ', $text);
        $wz = preg_replace('/\D/', '', (string) $wzCode);

        $doctor = preg_match(self::DOCTOR, $text, $match) ? $match[0] : null;
        $doctor ??= collect(self::DOCTOR_WZ)->contains(fn (string $prefix) => str_starts_with($wz, $prefix)) ? 'WZ '.$wzCode : null;

        if ($doctor === null) {
            return [null, null];
        }

        foreach ([self::CARE, self::OTHER_HEALTH, self::NOT_DOCTOR] as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                return ['kept', $match[0]];
            }
        }

        return ['doctor', $doctor];
    }

    /**
     * Was gelöscht würde (delete), was gesundheitsnah ist und bleibt (kept) und welche Arztpraxen schon bearbeitet sind (worked).
     * Jede Zeile: id, name, place, industry, hit.
     *
     * @param  list<int>  $keep  Vorgänge, die bleiben sollen
     * @return array{delete: Collection<int, array<string, mixed>>, kept: Collection<int, array<string, mixed>>, worked: Collection<int, array<string, mixed>>}
     */
    public function preview(array $keep = []): array
    {
        $rows = Lead::query()
            ->with('organization')
            ->where('status', 'new')
            ->whereNull('closed_at')
            ->whereNotNull('import_log_id')
            ->whereNotNull('organization_id')
            ->orderBy('id')
            ->get()
            ->map(function (Lead $lead) {
                [$type, $hit] = self::classify($lead->organization?->name, $lead->organization?->industry, $lead->organization?->wz_code);

                return $type === null ? null : [
                    'id' => $lead->id,
                    'name' => (string) $lead->organization?->name,
                    'place' => trim($lead->organization?->postal_code.' '.$lead->organization?->city),
                    'industry' => (string) $lead->organization?->industry,
                    'hit' => $hit,
                    'type' => $type,
                    // last_contact_at setzt das CRM schon beim Anlegen (Löschfrist), sagt also nichts über einen Anruf.
                    'untouched' => (int) $lead->call_attempts === 0,
                ];
            })
            ->filter()
            ->values();

        $doctors = $rows->where('type', 'doctor');
        $worked = $this->workedLeadIds($doctors->pluck('id'));
        $doctors = $doctors->map(fn (array $row) => [...$row, 'untouched' => $row['untouched'] && ! $worked->contains($row['id'])]);

        return [
            'delete' => $doctors->filter(fn (array $row) => $row['untouched'] && ! in_array($row['id'], $keep, true))->values(),
            'kept' => $rows->where('type', 'kept')->values(),
            'worked' => $doctors->reject(fn (array $row) => $row['untouched'])->values(),
        ];
    }

    /**
     * Endgültig löschen: Vorgänge, dann Betriebe ohne weiteren Vorgang samt ihrer Kontakte und Kontakte ohne Vorgang.
     *
     * @param  list<int>  $keep
     * @return array<string, int>
     */
    public function run(array $keep = []): array
    {
        $leadIds = $this->preview($keep)['delete']->pluck('id');

        return DB::transaction(fn () => RecordPurger::withAuditDeletion(function () use ($leadIds) {
            $leads = Lead::whereIn('id', $leadIds)->get(['id', 'organization_id', 'contact_id']);
            $counts = $this->purger->leads($leadIds);

            $organizationIds = Organization::whereIn('id', $leads->pluck('organization_id')->unique())
                ->whereDoesntHave('leads')
                ->pluck('id');
            $contactIds = Contact::query()
                ->where(fn ($q) => $q->whereIn('organization_id', $organizationIds)->orWhereIn('id', $leads->pluck('contact_id')->filter()))
                ->whereDoesntHave('leads')
                ->whereDoesntHave('participants')
                ->pluck('id');

            $counts['contacts'] = $this->purger->contacts($contactIds);
            $counts['organizations'] = $this->purger->organizations($organizationIds);

            activity('cleanup')
                ->event('sorted_out')
                ->withProperties(['criteria' => 'Arzt- und Zahnarztpraxen', 'counts' => $counts])
                ->log('Aussortiert: Arzt- und Zahnarztpraxen aus Leadlisten (neue, unbearbeitete Vorgänge)');

            return $counts;
        }));
    }

    /**
     * Vorgänge mit Aktivität, Termin, Dokument, Teilnehmerakte oder Förderfall gelten als bearbeitet.
     *
     * @param  Collection<int, int>  $leadIds
     * @return Collection<int, int>
     */
    private function workedLeadIds(Collection $leadIds): Collection
    {
        return collect([Activity::class, Appointment::class, Document::class, Participant::class, FundingCase::class])
            ->flatMap(fn (string $model) => $leadIds->chunk(500)->flatMap(fn (Collection $chunk) => $model::whereIn('lead_id', $chunk)->pluck('lead_id')))
            ->unique()
            ->values();
    }
}
