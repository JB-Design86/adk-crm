@php
    use App\Filament\Resources\Leads\LeadResource;
    use App\Support\Adk;

    $days = $this->days();
    $entries = $this->entries;
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <x-filament::button color="gray" size="sm" wire:click="previous" icon="heroicon-o-chevron-left" aria-label="zurück" />
            <x-filament::button color="gray" size="sm" wire:click="today">Heute</x-filament::button>
            <x-filament::button color="gray" size="sm" wire:click="next" icon="heroicon-o-chevron-right" aria-label="weiter" />
            <span class="ms-2 text-lg font-semibold">
                @if ($mode === 'week')
                    KW {{ $days[0]->isoWeek() }} · {{ $days[0]->format('d.m.') }} bis {{ end($days)->format('d.m.Y') }}
                @else
                    {{ $days[0]->locale('de')->isoFormat('dddd, D. MMMM YYYY') }}
                @endif
            </span>
        </div>
        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 text-sm">
                <x-filament::input.checkbox wire:model.live="onlyMine" />
                nur meine
            </label>
            <x-filament::tabs>
                <x-filament::tabs.item :active="$mode === 'day'" wire:click="$set('mode', 'day')">Tag</x-filament::tabs.item>
                <x-filament::tabs.item :active="$mode === 'week'" wire:click="$set('mode', 'week')">Woche</x-filament::tabs.item>
            </x-filament::tabs>
        </div>
    </div>

    <div @class(['grid gap-3', 'md:grid-cols-7' => $mode === 'week'])>
        @foreach ($days as $day)
            @php($entry = $entries[$day->toDateString()])
            @php($holiday = $this->holidayName($day))
            <div @class([
                'min-h-40 rounded-xl border bg-white p-3 dark:bg-gray-900',
                'border-primary-500 ring-1 ring-primary-500' => $day->isToday(),
                'border-gray-200 dark:border-white/10' => ! $day->isToday(),
                'bg-gray-50 dark:bg-white/5' => $day->isWeekend() || $holiday,
            ])>
                <button type="button" wire:click="showDay('{{ $day->toDateString() }}')" class="mb-2 block text-left">
                    <span class="text-sm font-semibold">{{ $day->locale('de')->isoFormat('dd, D.M.') }}</span>
                    @if ($holiday)
                        <span class="block text-xs text-gray-500">{{ $holiday }}</span>
                    @endif
                </button>

                <div class="space-y-1">
                    @foreach ($entry['appointments'] as $appointment)
                        <a href="{{ LeadResource::getUrl('view', ['record' => $appointment->lead_id]) }}"
                           class="block rounded-md bg-primary-50 px-2 py-1 text-xs hover:bg-primary-100 dark:bg-primary-500/10 dark:hover:bg-primary-500/20">
                            <span class="font-semibold">{{ $appointment->starts_at->format('H:i') }}</span>
                            {{ $appointment->typeLabel() }} · {{ $appointment->lead->displayName() }}
                            @if ($mode === 'day' && $appointment->user)
                                <span class="text-gray-500">· {{ $appointment->user->name }}</span>
                            @endif
                        </a>
                    @endforeach

                    @foreach ($entry['followUps'] as $lead)
                        <a href="{{ LeadResource::getUrl('view', ['record' => $lead]) }}"
                           class="block truncate rounded-md px-2 py-1 text-xs hover:bg-gray-100 dark:hover:bg-white/5">
                            <span class="text-gray-500">WV</span> {{ $lead->displayName() }}
                            @if ($mode === 'day')
                                <span class="text-gray-500">· {{ Adk::statusLabel($lead->status) }}</span>
                            @endif
                        </a>
                    @endforeach

                    @if ($entry['followUpCount'] > count($entry['followUps']))
                        <button type="button" wire:click="showDay('{{ $day->toDateString() }}')" class="px-2 text-xs text-primary-600 hover:underline dark:text-primary-400">
                            + {{ $entry['followUpCount'] - count($entry['followUps']) }} weitere Wiedervorlagen
                        </button>
                    @endif

                    @foreach ($entry['crossSelling'] as $lead)
                        <a href="{{ LeadResource::getUrl('view', ['record' => $lead]) }}"
                           class="block truncate rounded-md px-2 py-1 text-xs text-info-700 hover:bg-gray-100 dark:text-info-400 dark:hover:bg-white/5">
                            JB · {{ $lead->displayName() }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    <p class="text-xs text-gray-500">Termine farbig, „WV“ = Wiedervorlage, „JB“ = Wiedervorlage Cross-Selling JB Design.</p>
</x-filament-panels::page>
