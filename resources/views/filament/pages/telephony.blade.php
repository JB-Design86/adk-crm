<x-filament-panels::page>
    @php($connection = $this->connection())

    @if (! $this->configured())
        <x-filament::section icon="heroicon-o-wrench-screwdriver">
            <x-slot name="heading">sipgate ist noch nicht eingerichtet</x-slot>
            <p class="text-sm">
                Die Verwaltung trägt einmalig die Zugangsdaten der sipgate-Anwendung (Client-ID und Secret) in die Server-Einstellungen ein.
                Die Anleitung steht in <code>docs/BETRIEB.md</code> unter „sipgate“. Danach erscheint hier der Knopf „Mit sipgate verbinden“.
            </p>
        </x-filament::section>
    @elseif (! $connection)
        <x-filament::section icon="heroicon-o-link">
            <x-slot name="heading">Nicht verbunden</x-slot>
            <div class="space-y-3 text-sm">
                <p>Verbinden Sie einmal Ihr eigenes sipgate-Konto. Danach können Sie Vorgänge per Klick anrufen, und das CRM übernimmt Ihre Telefonate automatisch als Aktivität.</p>
                <p>Sie werden zur Anmeldeseite von sipgate weitergeleitet und bestätigen dort die Freigabe. Ihr sipgate-Kennwort sieht das CRM dabei nicht.</p>
                <x-filament::button tag="a" :href="route('filament.crm.sipgate.connect')" icon="heroicon-o-link">Mit sipgate verbinden</x-filament::button>
            </div>
        </x-filament::section>
    @else
        <x-filament::section icon="heroicon-o-check-circle" icon-color="success">
            <x-slot name="heading">Verbunden</x-slot>
            <dl class="grid gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">sipgate-Benutzer</dt><dd class="font-medium">{{ $connection->sipgate_user_id }}</dd></div>
                <div><dt class="text-gray-500">verbunden seit</dt><dd class="font-medium">{{ $connection->created_at->format('d.m.Y H:i') }}</dd></div>
                <div><dt class="text-gray-500">letzter Abgleich</dt><dd class="font-medium">{{ $connection->last_synced_at?->format('d.m.Y H:i') ?? 'noch keiner' }}</dd></div>
            </dl>
        </x-filament::section>

        <x-filament::section icon="heroicon-o-device-phone-mobile">
            <x-slot name="heading">Gerät für „Anrufen“</x-slot>
            <x-slot name="description">Dieses Gerät klingelt zuerst, wenn Sie im CRM auf „Über sipgate anrufen“ klicken. Nach dem Abheben wählt sipgate die Nummer.</x-slot>

            @php($devices = $this->devices())
            @if ($devicesError)
                <p class="text-sm text-red-600 dark:text-red-400">{{ $devicesError }}</p>
            @elseif (count($devices) === 0)
                <p class="text-sm text-gray-500">In Ihrem sipgate-Konto ist kein Gerät eingerichtet.</p>
            @else
                <form wire:submit="saveDevice" class="flex flex-wrap items-end gap-3">
                    <label class="block min-w-72 text-sm">
                        <span class="mb-1 block font-medium">Gerät</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model="deviceId">
                                <option value="">Bitte wählen</option>
                                @foreach ($devices as $device)
                                    <option value="{{ $device['id'] }}">{{ $device['alias'] }} · {{ \App\Filament\Pages\Telephony::deviceType($device['type']) }}{{ $device['online'] ? ' · online' : '' }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                    <x-filament::button type="submit" icon="heroicon-o-check">Speichern</x-filament::button>
                </form>
                @if ($connection->device_alias)
                    <p class="mt-3 text-sm text-gray-500">Aktuell: {{ $connection->device_alias }}</p>
                @endif
            @endif
        </x-filament::section>

        @php($channels = $this->channels())
        @if ($channels !== null)
            @php($aliases = collect($this->devices())->pluck('alias', 'id'))
            @php($used = \App\Services\Sipgate\SipgateClient::channelForDevice($channels, $connection->device_id))
            <x-filament::section icon="heroicon-o-queue-list">
                <x-slot name="heading">Channels bei sipgate</x-slot>
                <x-slot name="description">Bei der neuen sipgate-Anlage läuft jeder Anruf über einen Channel. So meldet sipgate Ihre Channels und Ihre Geräte darin.</x-slot>

                @if ($channelsError)
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $channelsError }}</p>
                @elseif (count($channels) === 0)
                    <p class="text-sm text-gray-500">sipgate meldet keine Channels.</p>
                @else
                    <ul class="divide-y divide-gray-100 text-sm dark:divide-white/10">
                        @foreach ($channels as $channel)
                            <li class="flex flex-wrap items-center gap-2 py-2">
                                <span class="font-medium">{{ $channel['name'] }}</span>
                                @if (($used['id'] ?? null) === $channel['id'])
                                    <x-filament::badge color="success">wird für „Anrufen“ genutzt</x-filament::badge>
                                @endif
                                <span class="basis-full text-gray-500">
                                    @if ($channel['deviceIds'] === null)
                                        Sie sind in diesem Channel nicht eingetragen.
                                    @elseif (count($channel['deviceIds']) === 0)
                                        Sie sind eingetragen, aber ohne Gerät.
                                    @else
                                        Ihre Geräte: {{ collect($channel['deviceIds'])->map(fn ($id) => $aliases[$id] ?? $id)->join(', ') }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($connection->device_id && ! collect($channels)->contains(fn ($channel) => in_array($connection->device_id, $channel['deviceIds'] ?? [], true)))
                        <p class="mt-3 text-sm text-amber-700 dark:text-amber-400">
                            Das Gerät „{{ $connection->device_alias }}“ ist in keinem Ihrer Channels eingetragen. Dann klingelt es beim Anruf per Klick womöglich nicht.
                            In sipgate unter Channels können Sie das Gerät bei sich eintragen.
                        </p>
                    @endif
                @endif
            </x-filament::section>
        @endif
    @endif

    <x-filament::section icon="heroicon-o-question-mark-circle" collapsible collapsed>
        <x-slot name="heading">So funktioniert es</x-slot>
        <ul class="list-disc space-y-1 pl-5 text-sm">
            <li><strong>Anrufen per Klick:</strong> In der Anrufliste und auf jeder Vorgangsseite gibt es „Über sipgate anrufen“. Sperrliste und Einwilligung werden vorher geprüft, genau wie beim normalen Anruf.</li>
            <li><strong>Automatische Protokollierung:</strong> Alle 5 Minuten holt das CRM Ihre neuen Anrufe aus sipgate. Passt die Nummer zu einem Vorgang, erscheint dort eine Aktivität „Telefonat (sipgate)“ mit Richtung, Ergebnis und Dauer. Anrufe mit unbekannten Nummern werden nicht übernommen.</li>
            <li><strong>Status setzen Sie weiter selbst:</strong> sipgate weiß nur, ob abgehoben wurde, nicht was besprochen wurde. Das Ergebnis tragen Sie wie gewohnt mit den Tasten 0 bis 9 ein.</li>
            <li><strong>Trennen:</strong> Mit „Verbindung trennen“ endet der Zugriff sofort. Wird ein Konto gesperrt, trennt das CRM die Verbindung automatisch.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
