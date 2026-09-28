<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\ReportService;
use App\Support\Hilfe;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Auswertung je Tag und Woche: Anrufe, erreicht, Termine, Unterlagen versendet;
 * Quoten je Branche, Kanal, Importquelle; „Datensatz falsch“ je Importquelle.
 */
class Reports extends Page
{
    protected string $view = 'filament.pages.reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Akquise';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Auswertung';

    protected static ?string $slug = 'auswertung';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    #[Url]
    public ?int $userId = null;

    public static function canAccess(): bool
    {
        return Gate::allows('reports.view');
    }

    public function mount(): void
    {
        $this->from ??= today()->startOfWeek()->toDateString();
        $this->until ??= today()->endOfWeek()->toDateString();
    }

    public function preset(string $preset): void
    {
        [$from, $until] = match ($preset) {
            'this_week' => [today()->startOfWeek(), today()->endOfWeek()],
            'last_week' => [today()->subWeek()->startOfWeek(), today()->subWeek()->endOfWeek()],
            'last_4_weeks' => [today()->subWeeks(3)->startOfWeek(), today()->endOfWeek()],
            'this_month' => [today()->startOfMonth(), today()->endOfMonth()],
            default => [today(), today()],
        };

        $this->from = $from->toDateString();
        $this->until = $until->toDateString();
    }

    #[Computed]
    public function report(): ReportService
    {
        $from = CarbonImmutable::parse($this->from ?: today());
        $until = CarbonImmutable::parse($this->until ?: today());

        if ($until->lt($from)) {
            [$from, $until] = [$until, $from];
        }

        // Höchstens ein Jahr auf einmal.
        if ($from->diffInDays($until) > 366) {
            $from = $until->subYear();
        }

        return ReportService::forPeriod($from->toDateString(), $until->toDateString(), $this->userId ?: null);
    }

    /** @return array<int, string> */
    public function users(): array
    {
        return User::orderBy('name')->pluck('name', 'id')->all();
    }

    public static function percent(?float $value): string
    {
        return $value === null ? '–' : number_format($value * 100, 0, ',', '.').' %';
    }

    public function getSubheading(): ?string
    {
        return Hilfe::seite('auswertung');
    }
}
