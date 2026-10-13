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
 * Gesundheitswesen aus den Leadlisten aussortieren (Auftrag Janosch, 13.10.2026): Arzt- und Zahnarztpraxen,
 * Therapeuten, Kliniken dürfen kaum werben (Berufsordnung, Heilmittelwerbegesetz) und passen nicht zum Projekt.
 *
 * Erfasst nur unbearbeitete Vorgänge aus Importen: Status „Neu“, offen, ohne Anrufversuch, ohne Aktivität, Termin,
 * Dokument, Teilnehmerakte oder Förderfall. Grenzfälle (Tierärzte, Apotheken, Pflege, Optiker, Sanitätshäuser,
 * Medizintechnik) dürfen werben und bleiben, außer sie werden ausdrücklich mitgenommen.
 * Gelöscht wird endgültig über den RecordPurger, im Protokoll stehen nur Anzahlen.
 */
class HealthSectorCleanup
{
    /** Kein Gesundheitswesen, obwohl ein Treffer darin steckt (Fahrradklinik, Praxisbedarf …). Wird vor dem Prüfen entfernt. */
    private const NOT_HEALTH = '/(fahrrad|rad|handy|auto|kfz|computer|pc|laptop|smartphone|tablet|uhren|schuh|puppen|teppich|baum|reifen|moebel|pflanzen)klinik|praxis(boerse|bedarf|software|einrichtung)/u';

    /** Gesundheitsnah, darf aber werben. Hat Vorrang vor HEALTH. */
    private const BORDERLINE = '/tierarzt|tieraerzt|tierklinik|tierheil|tiermedizin|tierphysio|tierpraxis|tierther|veterinaer|med\.?\s?vet\b|apothek|pflegedienst|pflegeheim|altenpflege|krankenpflege|intensivpflege|tagespflege|kurzzeitpflege|seniorenpflege|ambulante pflege|pflegezentrum|pflegeteam|altenheim|seniorenheim|seniorenresidenz|augenoptik|optiker|\boptik\b|hoerakust|hoergeraet|sanitaetshaus|orthopaedietechn|orthopaedieschuh|medizintechn|medizinprodukt|pharma|fusspflege/u';

    /** Heilberufe, Praxen, Kliniken. */
    private const HEALTH = '/zahnarzt|zahnaerzt|arzt|aerzt|praxis|kieferorthop|orthopaed|zahnmedizin|zahntechn|zahnklinik|zahnheilk|dental|physio|ergother|logopaed|heilprakt|naturheil|psychotherap|psychiat|psycholog|osteopath|chiropr|podolog|akupunkt|hebamme|geburtshaus|klinik|krankenhaus|hospiz|hospital(?!ity)|\bmvz\b|\bhno\b|medizin|\bdr\.?\s?med\b|chirurg|dermatolog|gynaekolog|urolog|neurolog|kardiolog|radiolog|onkolog|internist|anaesthes|\breha\b|rehabilit|therapie|therapeut|gesundheitswesen|gesundheitszentrum/u';

    /** WZ 2008: 86 Gesundheitswesen; 87 Heime, 75 Veterinärwesen, 47.73/47.74/47.78.1 Apotheken, Sanitätsbedarf, Augenoptiker. */
    private const HEALTH_WZ = ['86'];

    private const BORDERLINE_WZ = ['87', '75', '4773', '4774', '47781'];

    public function __construct(private RecordPurger $purger) {}

    /**
     * Treffer im Namen bzw. in der Branche: 'health', 'borderline' oder null, dazu das gefundene Wort.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function classify(?string $name, ?string $industry = null, ?string $wzCode = null): array
    {
        $text = strtr(mb_strtolower(trim($name.' '.$industry)), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $text = preg_replace(self::NOT_HEALTH, ' ', $text);
        $wz = preg_replace('/\D/', '', (string) $wzCode);

        if (preg_match(self::BORDERLINE, $text, $match)) {
            return ['borderline', $match[0]];
        }

        if (preg_match(self::HEALTH, $text, $match)) {
            return ['health', $match[0]];
        }

        foreach (self::BORDERLINE_WZ as $prefix) {
            if ($wz !== '' && str_starts_with($wz, $prefix)) {
                return ['borderline', 'WZ '.$wzCode];
            }
        }

        foreach (self::HEALTH_WZ as $prefix) {
            if ($wz !== '' && str_starts_with($wz, $prefix)) {
                return ['health', 'WZ '.$wzCode];
            }
        }

        return [null, null];
    }

    /**
     * Was gelöscht würde (delete), was als Grenzfall bleibt (borderline) und was schon bearbeitet ist (worked).
     * Jede Zeile: id, name, place, industry, hit.
     *
     * @param  list<int>  $keep  Vorgänge, die bleiben sollen
     * @return array{delete: Collection<int, array<string, mixed>>, borderline: Collection<int, array<string, mixed>>, worked: Collection<int, array<string, mixed>>}
     */
    public function preview(array $keep = [], bool $withBorderline = false): array
    {
        $rows = Lead::query()
            ->with('organization')
            ->where('status', 'new')
            ->whereNull('closed_at')
            ->whereNotNull('import_log_id')
            ->whereNotNull('organization_id')
            ->whereNotIn('id', $keep)
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

        $worked = $this->workedLeadIds($rows->pluck('id'));
        $rows = $rows->map(fn (array $row) => [...$row, 'untouched' => $row['untouched'] && ! $worked->contains($row['id'])]);
        $deletable = fn (array $row) => $row['untouched'] && ($row['type'] === 'health' || $withBorderline);

        return [
            'delete' => $rows->filter($deletable)->values(),
            'borderline' => $rows->filter(fn (array $row) => $row['type'] === 'borderline' && ! $withBorderline)->values(),
            'worked' => $rows->reject(fn (array $row) => $row['untouched'])->values(),
        ];
    }

    /**
     * Endgültig löschen: Vorgänge, dann Betriebe ohne weiteren Vorgang samt ihrer Kontakte und Kontakte ohne Vorgang.
     *
     * @param  list<int>  $keep
     * @return array<string, int>
     */
    public function run(array $keep = [], bool $withBorderline = false): array
    {
        $leadIds = $this->preview($keep, $withBorderline)['delete']->pluck('id');

        return DB::transaction(fn () => RecordPurger::withAuditDeletion(function () use ($leadIds, $withBorderline) {
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
                ->withProperties(['criteria' => 'Gesundheitswesen'.($withBorderline ? ' mit Grenzfällen' : ''), 'counts' => $counts])
                ->log('Aussortiert: Gesundheitswesen aus Leadlisten (neue, unbearbeitete Vorgänge)');

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
