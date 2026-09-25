<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Termine heute</x-slot>
        <x-slot name="afterHeader">
            <label class="flex items-center gap-2 text-sm">
                <x-filament::input.checkbox wire:model.live="onlyMine" />
                nur meine
            </label>
        </x-slot>

        @forelse ($this->appointments as $appointment)
            <a href="{{ \App\Filament\Resources\Leads\LeadResource::getUrl('view', ['record' => $appointment->lead_id]) }}"
               class="flex items-center gap-4 border-b border-gray-100 py-2 text-sm last:border-0 hover:bg-gray-50 dark:border-white/5 dark:hover:bg-white/5">
                <span class="w-16 font-semibold">{{ $appointment->starts_at->format('H:i') }}</span>
                <x-filament::badge color="gray">{{ $appointment->typeLabel() }}</x-filament::badge>
                <span class="grow">{{ $appointment->lead->displayName() }}</span>
                <span class="text-gray-500">{{ $appointment->user?->name }}</span>
            </a>
        @empty
            <p class="text-sm text-gray-500">Heute sind keine Termine eingetragen.</p>
        @endforelse
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
