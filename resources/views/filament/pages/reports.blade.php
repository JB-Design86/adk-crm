@php
    use App\Filament\Pages\Reports;

    $report = $this->report;
    $totals = $report->totals();
    $perDay = $report->perDay();
    $perWeek = $report->perWeek();
    $input = 'rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5';
    $th = 'px-3 py-2 text-left font-semibold whitespace-nowrap';
    $td = 'px-3 py-1.5 whitespace-nowrap';
    $num = 'px-3 py-1.5 text-right tabular-nums';
    $metricLabels = ['calls' => 'Anrufe', 'reached' => 'erreicht', 'appointments' => 'Termine', 'documents' => 'Unterlagen versendet'];
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm">von<br><input type="date" wire:model.live="from" class="{{ $input }}"></label>
        <label class="text-sm">bis<br><input type="date" wire:model.live="until" class="{{ $input }}"></label>
        <label class="text-sm">Person<br>
            <select wire:model.live="userId" class="{{ $input }}">
                <option value="">alle</option>
                @foreach ($this->users() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex flex-wrap gap-2">
            <x-filament::button size="sm" color="gray" wire:click="preset('today')">Heute</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="preset('this_week')">diese Woche</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="preset('last_week')">letzte Woche</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="preset('last_4_weeks')">letzte 4 Wochen</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="preset('this_month')">dieser Monat</x-filament::button>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($metricLabels as $key => $label)
            <x-filament::section>
                <p class="text-sm text-gray-500">{{ $label }}</p>
                <p class="text-3xl font-semibold tabular-nums">{{ number_format($totals[$key], 0, ',', '.') }}</p>
                @if ($key === 'reached')
                    <p class="text-sm text-gray-500">{{ Reports::percent($totals['calls'] ? $totals['reached'] / $totals['calls'] : null) }} der Anrufe</p>
                @elseif ($key === 'appointments')
                    <p class="text-sm text-gray-500">{{ Reports::percent($totals['reached'] ? $totals['appointments'] / $totals['reached'] : null) }} der Erreichten</p>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Je Tag</x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="{{ $th }}">Tag</th>
                        @foreach ($metricLabels as $label)<th class="{{ $th }} text-right">{{ $label }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($perDay as $day => $metrics)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="{{ $td }}">{{ \Carbon\CarbonImmutable::parse($day)->locale('de')->isoFormat('dd, DD.MM.') }}</td>
                                @foreach ($metricLabels as $key => $label)<td class="{{ $num }}">{{ $metrics[$key] }}</td>@endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Je Woche</x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="{{ $th }}">Woche</th>
                        @foreach ($metricLabels as $label)<th class="{{ $th }} text-right">{{ $label }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($perWeek as $week => $metrics)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="{{ $td }}">{{ $week }}</td>
                                @foreach ($metricLabels as $key => $label)<td class="{{ $num }}">{{ $metrics[$key] }}</td>@endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>

    @foreach (['industry' => 'Quoten je Branche', 'channel' => 'Quoten je Eingangskanal', 'source' => 'Quoten je Importquelle'] as $dimension => $heading)
        @php($rows = $report->byDimension($dimension))
        <x-filament::section collapsible>
            <x-slot name="heading">{{ $heading }}</x-slot>
            @if (count($rows))
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="{{ $th }}">{{ ['industry' => 'Branche', 'channel' => 'Kanal', 'source' => 'Quelle'][$dimension] }}</th>
                            @foreach ($metricLabels as $label)<th class="{{ $th }} text-right">{{ $label }}</th>@endforeach
                            <th class="{{ $th }} text-right">Erreichtquote</th>
                            <th class="{{ $th }} text-right">Terminquote</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="{{ $td }}">{{ $row['label'] }}</td>
                                    @foreach ($metricLabels as $key => $label)<td class="{{ $num }}">{{ $row[$key] }}</td>@endforeach
                                    <td class="{{ $num }}">{{ Reports::percent($row['reached_rate']) }}</td>
                                    <td class="{{ $num }}">{{ Reports::percent($row['appointment_rate']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-gray-500">Erreichtquote = erreicht / Anrufe. Terminquote = Termine / erreicht.</p>
            @else
                <p class="text-sm text-gray-500">Keine Aktivitäten im Zeitraum.</p>
            @endif
        </x-filament::section>
    @endforeach

    @php($wrong = $report->wrongDataBySource())
    <x-filament::section collapsible>
        <x-slot name="heading">„Datensatz falsch“ je Importquelle</x-slot>
        <x-slot name="description">Rückmeldung an die Prüfstufen der Leadliste.</x-slot>
        @if (count($wrong))
            <table class="w-full text-sm">
                <thead><tr class="border-b border-gray-200 dark:border-white/10">
                    <th class="{{ $th }}">Quelle</th>
                    <th class="{{ $th }} text-right">Datensatz falsch</th>
                    <th class="{{ $th }} text-right">Betriebe aus der Quelle</th>
                    <th class="{{ $th }} text-right">Anteil</th>
                </tr></thead>
                <tbody>
                    @foreach ($wrong as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="{{ $td }}">{{ $row['source'] }}</td>
                            <td class="{{ $num }}">{{ $row['wrong'] }}</td>
                            <td class="{{ $num }}">{{ $row['leads'] }}</td>
                            <td class="{{ $num }}">{{ Reports::percent($row['rate']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-sm text-gray-500">Im Zeitraum wurde kein Datensatz als falsch markiert.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
