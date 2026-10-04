@php
    $participant = $this->record;
    $contact = $participant->contact;
    $checklist = $participant->checklist();
    $progress = $participant->progress();
    $missing = $this->missingContractData();
    $phases = config('adk.checklist_phases');
@endphp

<x-filament-panels::page>
    @if ($missing)
        <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:border-warning-500/40 dark:bg-warning-500/10">
            <span class="font-semibold">Für den Vertrag fehlen noch:</span> {{ implode(', ', $missing) }}. Oben unter „Daten“ eintragen.
        </div>
    @endif

    @if ($participant->follow_up_on && $participant->follow_up_on->lte(today()))
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm dark:border-danger-500/40 dark:bg-danger-500/10">
            <span class="font-semibold">Verbleibserhebung fällig</span> seit {{ $participant->follow_up_on->format('d.m.Y') }}. Nachbefragung versenden und oben „Verbleib erfassen“.
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Checkliste --}}
        <x-filament::section class="lg:col-span-2">
            <x-slot name="heading">Checkliste</x-slot>
            <x-slot name="description">{{ $progress['done'] }} von {{ $progress['total'] }} Punkten erledigt. Jeder Punkt mit Datum und, wo vorhanden, dem Dokument.</x-slot>

            <div class="space-y-6">
                @foreach ($phases as $phase => $phaseLabel)
                    @continue(! $checklist->has($phase))
                    <div>
                        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ $phaseLabel }}</h3>
                        <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-white/5 dark:border-white/10">
                            @foreach ($checklist[$phase] as $row)
                                @php($item = $row['item'])
                                @php($checks = $row['checks'])
                                <li class="flex items-start gap-3 p-3">
                                    <span @class([
                                        'mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                        'bg-success-600 text-white' => $checks->isNotEmpty(),
                                        'bg-gray-200 text-gray-600 dark:bg-white/10 dark:text-gray-300' => $checks->isEmpty(),
                                    ])>{{ $checks->isNotEmpty() ? '✓' : '' }}</span>
                                    <div class="grow">
                                        <p @class(['font-medium', 'text-gray-500' => $checks->isEmpty()])>
                                            {{ $item->name }}
                                            @if ($item->repeatable)<span class="text-xs font-normal text-gray-500">(mehrfach)</span>@endif
                                        </p>
                                        @foreach ($checks as $check)
                                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                                {{ $check->done_on->format('d.m.Y') }}
                                                @if ($check->user) · {{ $check->user->name }} @endif
                                                @if ($check->document && $check->document->isVisibleTo(auth()->user()))
                                                    · <a href="{{ route('filament.crm.documents.show', $check->document) }}" target="_blank" class="text-primary-600 hover:underline dark:text-primary-400">{{ $check->document->title }}</a>
                                                @endif
                                                @if ($check->note) · {{ $check->note }} @endif
                                                <button type="button" wire:click="undoCheck({{ $check->id }})" wire:confirm="Eintrag „{{ $item->name }}“ vom {{ $check->done_on->format('d.m.Y') }} zurücknehmen?" class="ml-1 text-xs text-gray-500 hover:text-danger-600 hover:underline">zurücknehmen</button>
                                            </p>
                                        @endforeach
                                    </div>
                                    @if ($checks->isEmpty() || $item->repeatable)
                                        <x-filament::button size="xs" color="gray" wire:click="mountAction('completeCheck', { item: {{ $item->id }} })">abhaken</x-filament::button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Daten --}}
        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">Person</x-slot>
                <dl class="space-y-2 text-sm">
                    <div><dt class="text-gray-500">Name</dt><dd class="font-medium">{{ $contact->fullName() }}</dd></div>
                    @if ($contact->organization)
                        <div><dt class="text-gray-500">Betrieb</dt><dd>{{ $contact->organization->name }}</dd></div>
                    @endif
                    <div><dt class="text-gray-500">Geburtsdatum</dt><dd>{{ $participant->birth_date?->format('d.m.Y') ?? '–' }}</dd></div>
                    <div><dt class="text-gray-500">Anschrift</dt><dd>{{ $participant->street ? $participant->street.', '.$participant->postal_code.' '.$participant->city : '–' }}</dd></div>
                    <div><dt class="text-gray-500">Telefon</dt><dd>{{ $contact->phone_display ?? '–' }}</dd></div>
                    <div><dt class="text-gray-500">E-Mail</dt><dd>{{ $contact->email ?? '–' }}</dd></div>
                    @if ($participant->fundingCase)
                        <div><dt class="text-gray-500">Kundennummer beim Kostenträger</dt><dd>{{ $participant->fundingCase->customer_number ?? '–' }}</dd></div>
                        <div><dt class="text-gray-500">Gutscheinnummer</dt><dd>{{ $participant->fundingCase->voucher_number ?? '–' }}</dd></div>
                    @endif
                </dl>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Kurs</x-slot>
                <dl class="space-y-2 text-sm">
                    <div><dt class="text-gray-500">Kurs bzw. Maßnahme</dt><dd class="font-medium">{{ $participant->course_name ?? '–' }}</dd></div>
                    <div><dt class="text-gray-500">Zeitraum</dt><dd>{{ $participant->course_starts_on?->format('d.m.Y') ?? '–' }} bis {{ $participant->course_ends_on?->format('d.m.Y') ?? '–' }}</dd></div>
                    <div><dt class="text-gray-500">Stand</dt><dd><x-filament::badge :color="\App\Support\Adk::participantStateColor($participant->state)" class="inline-flex">{{ $participant->stateLabel() }}</x-filament::badge></dd></div>
                    @if ($participant->left_on)
                        <div><dt class="text-gray-500">letzter Kurstag</dt><dd>{{ $participant->left_on->format('d.m.Y') }}@if ($participant->exit_reason) · {{ $participant->exit_reason }}@endif</dd></div>
                    @endif
                    <div>
                        <dt class="text-gray-500">Verbleib</dt>
                        <dd>
                            @if ($participant->placement_status)
                                {{ \App\Support\Adk::placementLabel($participant->placement_status) }} (erhoben {{ $participant->placement_recorded_on?->format('d.m.Y') }})
                            @elseif ($participant->follow_up_on)
                                Erhebung am {{ $participant->follow_up_on->format('d.m.Y') }}
                            @else
                                nach Kursende
                            @endif
                        </dd>
                    </div>
                    @if ($participant->notes)
                        <div><dt class="text-gray-500">Notizen</dt><dd class="whitespace-pre-line">{{ $participant->notes }}</dd></div>
                    @endif
                </dl>
            </x-filament::section>

            @can('health.view')
                <x-filament::section icon="heroicon-o-lock-closed">
                    <x-slot name="heading">Gesundheitsangaben</x-slot>
                    <x-slot name="description">Nur für die Verwaltung sichtbar.</x-slot>
                    <dl class="space-y-2 text-sm">
                        <div><dt class="text-gray-500">Einwilligung</dt><dd>{{ $contact->health_consent_at ? 'am '.$contact->health_consent_at->format('d.m.Y') : 'keine eingetragen' }}</dd></div>
                        <div><dt class="text-gray-500">Nachteilsausgleich</dt><dd class="whitespace-pre-line">{{ $participant->accommodation_notes ?: '–' }}</dd></div>
                    </dl>
                </x-filament::section>
            @endcan
        </div>
    </div>

    <x-filament::section>
        <x-slot name="heading">Dokumente</x-slot>
        <x-slot name="description">Alle Unterlagen der Akte, auch die aus dem Förderfall. Verschlüsselt gespeichert, jeder Abruf im Protokoll.</x-slot>
        @livewire(\App\Livewire\DocumentList::class, ['lead' => $participant->lead, 'participant' => $participant], key('documents-'.$participant->id))
    </x-filament::section>
</x-filament-panels::page>
