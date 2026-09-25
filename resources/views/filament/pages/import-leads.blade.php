<x-filament-panels::page>
    @if ($step === 'upload')
        <form wire:submit="analyze" class="space-y-6">
            {{ $this->form }}

            <div>
                <x-filament::button type="submit" icon="heroicon-o-arrow-right">
                    Weiter zur Spaltenzuordnung
                </x-filament::button>
            </div>
        </form>
    @else
        <x-filament::section>
            <x-slot name="heading">Datei</x-slot>
            <dl class="grid gap-2 text-sm sm:grid-cols-4">
                <div><dt class="text-gray-500">Datei</dt><dd class="font-medium">{{ $originalName }}</dd></div>
                <div><dt class="text-gray-500">Quelle</dt><dd class="font-medium">{{ $data['source'] ?? '' }}</dd></div>
                <div><dt class="text-gray-500">Abrufdatum</dt><dd class="font-medium">{{ filled($data['retrieved_at'] ?? null) ? \Illuminate\Support\Carbon::parse($data['retrieved_at'])->format('d.m.Y') : '' }}</dd></div>
                <div><dt class="text-gray-500">Datenzeilen</dt><dd class="font-medium">{{ $preview['total'] ?? 0 }}</dd></div>
            </dl>
        </x-filament::section>

        <form wire:submit="runImport" class="space-y-6">
            {{ $this->mappingForm }}

            <x-filament::section>
                <x-slot name="heading">3. Vorschau der ersten Zeilen</x-slot>
                <x-slot name="description">So werden die Daten übernommen. Dubletten und Einträge auf der Sperrliste werden beim Einspielen übersprungen und im Importprotokoll aufgeführt.</x-slot>

                @php($rows = $this->mappedPreview())
                @if (count($rows) && count($rows[0]))
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-white/10">
                                    @foreach (array_keys($rows[0]) as $label)
                                        <th class="px-2 py-1 font-semibold whitespace-nowrap">{{ $label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        @foreach ($row as $value)
                                            <td class="px-2 py-1 whitespace-nowrap">{{ $value }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-gray-500">Noch keine Spalte zugeordnet.</p>
                @endif
            </x-filament::section>

            <div class="flex gap-3">
                <x-filament::button type="submit" icon="heroicon-o-arrow-down-tray">
                    Einspielen
                </x-filament::button>
                <x-filament::button color="gray" wire:click="restart" type="button">
                    Abbrechen
                </x-filament::button>
            </div>
        </form>
    @endif
</x-filament-panels::page>
