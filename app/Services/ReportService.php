<?php

namespace App\Services;

use App\Models\FundingStep;
use App\Support\Adk;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Kennzahlen der Akquise aus den Aktivitäten.
 *
 * Anruf = Aktivität vom Typ „call“ (Speichern in der Anrufliste).
 * Erreicht = Anruf mit einem Ergebnis, das in config('adk.statuses') als reached markiert ist.
 * Termin / Unterlagen versendet = Aktivität mit Ergebnis appointment bzw. documents_sent.
 */
class ReportService
{
    public function __construct(
        private CarbonImmutable $from,
        private CarbonImmutable $until,
        private ?int $userId = null,
    ) {}

    public static function forPeriod(string $from, string $until, ?int $userId = null): self
    {
        return new self(CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($until)->endOfDay(), $userId);
    }

    /** @return array<string, array{calls: int, reached: int, appointments: int, documents: int}> je Tag */
    public function perDay(): array
    {
        $rows = $this->base()
            ->selectRaw('DATE(activities.occurred_at) as day')
            ->selectRaw($this->metricsSql())
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $result = [];

        foreach (CarbonPeriod::create($this->from, $this->until->startOfDay()) as $day) {
            $row = $rows->get($day->toDateString());
            $result[$day->toDateString()] = $this->metrics($row);
        }

        return $result;
    }

    /** @return array<string, array{calls: int, reached: int, appointments: int, documents: int}> je Kalenderwoche */
    public function perWeek(): array
    {
        $weeks = [];

        foreach ($this->perDay() as $day => $metrics) {
            $date = CarbonImmutable::parse($day);
            $key = 'KW '.$date->isoWeek().' / '.$date->isoWeekYear();
            $weeks[$key] ??= ['calls' => 0, 'reached' => 0, 'appointments' => 0, 'documents' => 0];

            foreach ($metrics as $name => $value) {
                $weeks[$key][$name] += $value;
            }
        }

        return $weeks;
    }

    /** @return array{calls: int, reached: int, appointments: int, documents: int} */
    public function totals(): array
    {
        return $this->metrics($this->base()->selectRaw($this->metricsSql())->first());
    }

    /**
     * Quoten nach Branche, Kanal oder Importquelle.
     *
     * @param  'industry'|'channel'|'source'  $dimension
     * @return list<array{label: string, calls: int, reached: int, appointments: int, documents: int, reached_rate: ?float, appointment_rate: ?float}>
     */
    public function byDimension(string $dimension): array
    {
        $column = match ($dimension) {
            'industry' => 'organizations.industry',
            'channel' => 'leads.channel',
            'source' => 'organizations.source',
        };

        return $this->base()
            ->selectRaw("{$column} as label")
            ->selectRaw($this->metricsSql())
            ->groupBy($column)
            ->get()
            ->map(function ($row) use ($dimension) {
                $metrics = $this->metrics($row);
                $label = $row->label;

                if ($dimension === 'channel') {
                    $label = Adk::channelLabel($label);
                }

                return [
                    'label' => $label ?? 'ohne Angabe',
                    ...$metrics,
                    'reached_rate' => $metrics['calls'] ? $metrics['reached'] / $metrics['calls'] : null,
                    'appointment_rate' => $metrics['reached'] ? $metrics['appointments'] / $metrics['reached'] : null,
                ];
            })
            ->sortByDesc('calls')
            ->values()
            ->all();
    }

    /**
     * Telefonate laut sipgate-Anrufliste (Aktivitäten „Telefonat (sipgate)“): Gegenprobe zu den
     * in der Anrufliste gespeicherten Anrufen, dazu die Gesprächszeit.
     *
     * @return array{total: int, picked_up: int, talk_seconds: int, average_seconds: ?int}
     */
    public function phoneLog(): array
    {
        $row = DB::table('activities')
            ->whereBetween('occurred_at', [$this->from, $this->until])
            ->where('type', 'phone_log')
            ->when($this->userId, fn (Builder $q) => $q->where('user_id', $this->userId))
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN outcome = 'PICKUP' THEN 1 ELSE 0 END) as picked_up, SUM(CASE WHEN outcome = 'PICKUP' THEN COALESCE(duration_seconds, 0) ELSE 0 END) as talk_seconds")
            ->first();

        $pickedUp = (int) ($row->picked_up ?? 0);
        $talk = (int) ($row->talk_seconds ?? 0);

        return [
            'total' => (int) ($row->total ?? 0),
            'picked_up' => $pickedUp,
            'talk_seconds' => $talk,
            'average_seconds' => $pickedUp ? intdiv($talk, $pickedUp) : null,
        ];
    }

    /**
     * „Datensatz falsch“ je Importquelle (Rückmeldung an die Prüfstufen der Leadliste).
     *
     * @return list<array{source: string, wrong: int, leads: int, rate: ?float}>
     */
    public function wrongDataBySource(): array
    {
        $wrong = DB::table('activities')
            ->join('leads', 'leads.id', '=', 'activities.lead_id')
            ->leftJoin('organizations', 'organizations.id', '=', 'leads.organization_id')
            ->whereBetween('activities.occurred_at', [$this->from, $this->until])
            ->where('activities.outcome', 'wrong_data')
            ->when($this->userId, fn (Builder $q) => $q->where('activities.user_id', $this->userId))
            ->selectRaw('organizations.source as source, COUNT(DISTINCT leads.id) as wrong')
            ->groupBy('organizations.source')
            ->pluck('wrong', 'source');

        $totals = DB::table('organizations')
            ->selectRaw('source, COUNT(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        return $wrong
            ->map(fn ($count, $source) => [
                'source' => $source ?: 'ohne Angabe',
                'wrong' => (int) $count,
                'leads' => (int) ($totals[$source] ?? 0),
                'rate' => ($totals[$source] ?? 0) ? $count / $totals[$source] : null,
            ])
            ->sortByDesc('wrong')
            ->values()
            ->all();
    }

    /**
     * Förderweg im Zeitraum je Förderweg: gestartet, je Schritt erledigt/abgelehnt, eingeschrieben.
     * Beantwortet z. B. „Anfrage → Gutschein beantragt → Gutschein bewilligt“.
     *
     * @return list<array{pathway: string, label: string, started: int, enrolled: int, steps: list<array{name: string, done: int, rejected: int}>}>
     */
    public function fundingFunnel(): array
    {
        $completions = DB::table('funding_case_steps')
            ->join('funding_cases', 'funding_cases.id', '=', 'funding_case_steps.funding_case_id')
            ->join('leads', 'leads.id', '=', 'funding_cases.lead_id')
            ->whereBetween('funding_case_steps.completed_on', [$this->from->toDateString(), $this->until->toDateString()])
            ->when($this->userId, fn (Builder $q) => $q->where('funding_case_steps.user_id', $this->userId))
            ->selectRaw('funding_case_steps.funding_step_id as step_id, funding_case_steps.result as result, COUNT(*) as total')
            ->groupBy('funding_case_steps.funding_step_id', 'funding_case_steps.result')
            ->get()
            ->groupBy('step_id');

        $result = [];

        foreach (config('adk.funding_pathways') as $pathway => $definition) {
            $cases = DB::table('funding_cases')->where('pathway', $pathway);

            $steps = FundingStep::query()->forPathway($pathway)->get()->map(function (FundingStep $step) use ($completions) {
                $rows = $completions->get($step->id, collect());

                return [
                    'name' => $step->name,
                    'done' => (int) $rows->where('result', 'done')->sum('total'),
                    'rejected' => (int) $rows->where('result', 'rejected')->sum('total'),
                ];
            })->all();

            $result[] = [
                'pathway' => $pathway,
                'label' => $definition['label'],
                'started' => (clone $cases)->whereBetween('created_at', [$this->from, $this->until])->count(),
                'enrolled' => (clone $cases)->whereBetween('enrolled_at', [$this->from, $this->until])->count(),
                'steps' => $steps,
            ];
        }

        return $result;
    }

    private function base(): Builder
    {
        return DB::table('activities')
            ->join('leads', 'leads.id', '=', 'activities.lead_id')
            ->leftJoin('organizations', 'organizations.id', '=', 'leads.organization_id')
            ->whereBetween('activities.occurred_at', [$this->from, $this->until])
            ->whereIn('activities.type', ['call', 'status_change'])
            ->when($this->userId, fn (Builder $q) => $q->where('activities.user_id', $this->userId));
    }

    private function metricsSql(): string
    {
        $reached = "'".implode("','", Adk::reachedStatuses())."'";

        return implode(', ', [
            "SUM(CASE WHEN activities.type = 'call' THEN 1 ELSE 0 END) as calls",
            "SUM(CASE WHEN activities.type = 'call' AND activities.outcome IN ({$reached}) THEN 1 ELSE 0 END) as reached",
            "SUM(CASE WHEN activities.outcome = 'appointment' THEN 1 ELSE 0 END) as appointments",
            "SUM(CASE WHEN activities.outcome = 'documents_sent' THEN 1 ELSE 0 END) as documents",
        ]);
    }

    /** @return array{calls: int, reached: int, appointments: int, documents: int} */
    private function metrics(?object $row): array
    {
        return [
            'calls' => (int) ($row->calls ?? 0),
            'reached' => (int) ($row->reached ?? 0),
            'appointments' => (int) ($row->appointments ?? 0),
            'documents' => (int) ($row->documents ?? 0),
        ];
    }
}
