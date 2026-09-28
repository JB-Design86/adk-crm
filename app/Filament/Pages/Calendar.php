<?php

namespace App\Filament\Pages;

use App\Models\Appointment;
use App\Models\Lead;
use App\Support\Hilfe;
use App\Support\WorkingDays;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Kalender: Termine und Wiedervorlagen nach Tag und Woche.
 */
class Calendar extends Page
{
    protected string $view = 'filament.pages.calendar';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Akquise';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Kalender';

    protected static ?string $slug = 'kalender';

    #[Url]
    public string $mode = 'week';

    #[Url]
    public ?string $date = null;

    #[Url]
    public bool $onlyMine = false;

    public const DAY_LIMIT = 12;

    public static function canAccess(): bool
    {
        return Gate::allows('leads.view');
    }

    public function mount(): void
    {
        $this->date ??= today()->toDateString();
    }

    public function start(): CarbonImmutable
    {
        $date = CarbonImmutable::parse($this->date);

        return $this->mode === 'week' ? $date->startOfWeek() : $date->startOfDay();
    }

    /** @return list<CarbonImmutable> */
    public function days(): array
    {
        $start = $this->start();

        return $this->mode === 'week'
            ? array_map(fn (int $offset) => $start->addDays($offset), range(0, 6))
            : [$start];
    }

    public function previous(): void
    {
        $this->date = $this->start()->sub($this->mode === 'week' ? '1 week' : '1 day')->toDateString();
    }

    public function next(): void
    {
        $this->date = $this->start()->add($this->mode === 'week' ? '1 week' : '1 day')->toDateString();
    }

    public function today(): void
    {
        $this->date = today()->toDateString();
    }

    public function showDay(string $date): void
    {
        $this->mode = 'day';
        $this->date = $date;
    }

    /**
     * Einträge je Tag: Termine und Wiedervorlagen.
     *
     * @return array<string, array{appointments: list<Appointment>, followUps: list<Lead>, followUpCount: int, crossSelling: list<Lead>}>
     */
    #[Computed]
    public function entries(): array
    {
        $days = $this->days();
        $from = $days[0]->startOfDay();
        $until = end($days)->endOfDay();

        $appointments = Appointment::query()
            ->with(['lead.organization', 'lead.contact', 'user'])
            ->whereBetween('starts_at', [$from, $until])
            ->when($this->onlyMine, fn (Builder $q) => $q->where('user_id', auth()->id()))
            ->orderBy('starts_at')
            ->get()
            ->groupBy(fn (Appointment $appointment) => $appointment->starts_at->toDateString());

        $leadQuery = fn () => Lead::query()
            ->with(['organization', 'contact'])
            ->whereNull('closed_at')
            ->when($this->onlyMine, fn (Builder $q) => $q->where('assigned_to', auth()->id()));

        $followUps = $leadQuery()
            ->whereDate('next_action_at', '>=', $from->toDateString())
            ->whereDate('next_action_at', '<=', $until->toDateString())
            ->where('status', '!=', 'appointment')
            ->orderBy('next_action_at')
            ->get()
            ->groupBy(fn (Lead $lead) => $lead->next_action_at->toDateString());

        $crossSelling = $leadQuery()
            ->where('cross_selling', true)
            ->whereDate('cross_selling_follow_up_at', '>=', $from->toDateString())
            ->whereDate('cross_selling_follow_up_at', '<=', $until->toDateString())
            ->get()
            ->groupBy(fn (Lead $lead) => $lead->cross_selling_follow_up_at->toDateString());

        $result = [];

        foreach ($days as $day) {
            $key = $day->toDateString();
            $dayFollowUps = $followUps->get($key, collect());

            $result[$key] = [
                'appointments' => $appointments->get($key, collect())->all(),
                'followUps' => $this->mode === 'day' ? $dayFollowUps->all() : $dayFollowUps->take(self::DAY_LIMIT)->all(),
                'followUpCount' => $dayFollowUps->count(),
                'crossSelling' => $crossSelling->get($key, collect())->all(),
            ];
        }

        return $result;
    }

    public function holidayName(CarbonImmutable $day): ?string
    {
        return WorkingDays::holidays($day->year)[$day->toDateString()] ?? null;
    }

    public function getSubheading(): ?string
    {
        return Hilfe::seite('kalender');
    }
}
