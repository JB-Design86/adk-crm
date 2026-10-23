<div class="flex items-center">
    @if (count($options) > 1)
        <label class="flex items-center gap-2 text-sm" title="Welche Marke Heute, Anrufliste, Vorgänge, Kalender und Auswertung zeigen">
            <span class="sr-only">Marke</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="brand" aria-label="Marke">
                    <option value="">Alle Marken</option>
                    @foreach ($options as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    @elseif (count($options) === 1)
        <x-filament::badge :color="\App\Support\Brand::color(array_key_first($options))">{{ reset($options) }}</x-filament::badge>
    @endif
</div>
