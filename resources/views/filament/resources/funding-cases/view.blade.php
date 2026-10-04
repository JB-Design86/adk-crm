@php
    $case = $this->record;
    $lead = $case->lead;
    $done = $case->completedSteps()->with('user')->get()->keyBy('funding_step_id');
    $current = $case->isOpen() ? $case->currentStep() : null;
    $steps = $case->steps();
    $canEdit = \Illuminate\Support\Facades\Gate::allows('leads.edit');
    $presentDocuments = $lead->documents()->distinct()->pluck('category')->all();
    $missingDocuments = $case->missingDocuments();
    $participants = $case->participants()->with('contact')->get();
    $canSeeFiles = \Illuminate\Support\Facades\Gate::allows('participants');
@endphp

<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Schrittfolge --}}
        <x-filament::section class="lg:col-span-2">
            <x-slot name="heading">Schrittfolge</x-slot>
            <x-slot name="description">{{ $case->progressLabel() }} Schritten erledigt. Wiedervorlage: {{ $lead->next_action_at?->format('d.m.Y') ?? '–' }}</x-slot>

            @if ($case->isOpen() && ! $current)
                <div class="mb-4 rounded-lg border border-success-300 bg-success-50 p-4 dark:border-success-500/40 dark:bg-success-500/10">
                    <p class="font-semibold">Alle Schritte erledigt.</p>
                    @if ($missingDocuments)
                        <p class="text-sm"><span class="font-semibold text-danger-600 dark:text-danger-400">Vor der Einschreibung fehlen noch Unterlagen:</span> {{ implode(', ', array_map(fn ($c) => \App\Support\Adk::documentCategoryLabel($c), array_keys($missingDocuments))) }}. Bitte unten unter „Dokumente“ hochladen.</p>
                    @else
                        <p class="text-sm">Sobald der Kostenträger die Anmeldung bestätigt hat, oben „Einschreibung bestätigt“ wählen. Dann legt das CRM die Teilnehmerakte an.</p>
                    @endif
                </div>
            @endif

            <ol class="space-y-3">
                @foreach ($steps as $step)
                    @php($entry = $done->get($step->id))
                    @php($isCurrent = $current && $current->id === $step->id)
                    <li @class([
                        'rounded-lg border p-3',
                        'border-primary-500 bg-primary-50 ring-1 ring-primary-500 dark:bg-primary-500/10' => $isCurrent,
                        'border-gray-200 dark:border-white/10' => ! $isCurrent,
                    ])>
                        <div class="flex items-start gap-3">
                            <span @class([
                                'mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                'bg-success-600 text-white' => $entry && $entry->result === 'done',
                                'bg-danger-600 text-white' => $entry && $entry->result === 'rejected',
                                'bg-primary-600 text-white' => ! $entry && $isCurrent,
                                'bg-gray-200 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $entry && ! $isCurrent,
                            ])>
                                @if ($entry && $entry->result === 'done') ✓ @elseif ($entry) ✕ @else {{ $loop->iteration }} @endif
                            </span>
                            <div class="grow">
                                <p @class(['font-medium', 'text-gray-500' => ! $entry && ! $isCurrent])>{{ $step->name }}</p>
                                @if ($step->document_category)
                                    @php($hasDocument = in_array($step->document_category, $presentDocuments, true))
                                    <p @class(['text-xs', 'text-success-700 dark:text-success-400' => $hasDocument, 'text-danger-600 dark:text-danger-400' => ! $hasDocument && $step->requires_document, 'text-gray-500' => ! $hasDocument && ! $step->requires_document])>
                                        Unterlage: {{ \App\Support\Adk::documentCategoryLabel($step->document_category) }}
                                        · {{ $hasDocument ? 'liegt vor' : ($step->requires_document ? 'fehlt (Pflicht vor der Einschreibung)' : 'noch nicht hochgeladen') }}
                                    </p>
                                @endif

                                @if ($entry)
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        {{ $entry->resultLabel() }} am {{ $entry->completed_on->format('d.m.Y') }}
                                        @if ($entry->partyLabel()) · {{ $entry->partyLabel() }} @endif
                                        @if ($entry->user) · eingetragen von {{ $entry->user->name }} @endif
                                    </p>
                                    @if ($entry->note)
                                        <p class="mt-1 whitespace-pre-line text-sm">{{ $entry->note }}</p>
                                    @endif
                                @elseif ($isCurrent)
                                    <div class="mt-2 rounded-md bg-white p-3 text-sm shadow-sm dark:bg-gray-900">
                                        <p class="font-semibold">Was ist jetzt von unserer Seite zu tun?</p>
                                        <p class="mt-1 whitespace-pre-line">{{ $step->instructions ?: 'Noch kein Erklärtext hinterlegt (Verwaltung → Förderweg-Schritte).' }}</p>
                                        <p class="mt-2 text-xs text-gray-500">Danach Wiedervorlage in {{ $step->followUpLabel() }}.</p>
                                    </div>
                                @endif
                            </div>

                            @if ($entry && $canEdit && $case->isOpen())
                                <button type="button"
                                        wire:click="undoStep({{ $entry->id }})"
                                        wire:confirm="Eintrag „{{ $step->name }}“ zurücknehmen?"
                                        class="text-xs text-gray-500 hover:text-danger-600 hover:underline">
                                    zurücknehmen
                                </button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>

        {{-- Daten --}}
        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">Person / Betrieb</x-slot>
                <dl class="space-y-2 text-sm">
                    <div><dt class="text-gray-500">Name</dt><dd class="font-medium">{{ $lead->displayName() }}</dd></div>
                    @if ($lead->organization && $lead->contact)
                        <div><dt class="text-gray-500">Ansprechperson</dt><dd>{{ $lead->contact->fullName() }}</dd></div>
                    @endif
                    <div>
                        <dt class="text-gray-500">Telefon</dt>
                        <dd>
                            @if ($lead->phoneE164() && $lead->isCallable())
                                <a href="tel:{{ $lead->phoneE164() }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $lead->phoneDisplay() }}</a>
                            @else
                                {{ $lead->phoneDisplay() ?? '–' }}
                            @endif
                        </dd>
                    </div>
                    <div><dt class="text-gray-500">E-Mail</dt><dd>{{ $lead->contact?->email ?? $lead->organization?->email ?? '–' }}</dd></div>
                    <div><dt class="text-gray-500">Zielgruppe</dt><dd>{{ \App\Support\Adk::targetGroupLabel($lead->target_group) }}</dd></div>
                    <div><dt class="text-gray-500">zuständig</dt><dd>{{ $lead->assignee?->name ?? '–' }}</dd></div>
                </dl>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Förderung</x-slot>
                <dl class="space-y-2 text-sm">
                    <div><dt class="text-gray-500">Kundennummer beim Kostenträger</dt><dd>{{ $case->customer_number ?? '–' }}</dd></div>
                    <div><dt class="text-gray-500">Ansprechperson beim Kostenträger</dt><dd>{{ $case->funder_contact ?? '–' }}</dd></div>
                    <div><dt class="text-gray-500">Gutscheinnummer</dt><dd>{{ $case->voucher_number ?? '–' }}</dd></div>
                    <div>
                        <dt class="text-gray-500">Gutschein gültig bis</dt>
                        <dd @class(['font-semibold text-danger-600' => $case->voucher_valid_until && $case->voucher_valid_until->lt(today()->addDays(14))])>
                            {{ $case->voucher_valid_until?->format('d.m.Y') ?? '–' }}
                        </dd>
                    </div>
                    @if ($case->notes)
                        <div><dt class="text-gray-500">Notizen</dt><dd class="whitespace-pre-line">{{ $case->notes }}</dd></div>
                    @endif
                    @if ($case->state === 'cancelled')
                        <div><dt class="text-gray-500">Abgebrochen</dt><dd>{{ $case->cancelled_at?->format('d.m.Y') }}: {{ $case->cancel_reason }}</dd></div>
                    @endif
                    @if ($case->state === 'enrolled')
                        <div><dt class="text-gray-500">Eingeschrieben</dt><dd>{{ $case->enrolled_at?->format('d.m.Y') }}</dd></div>
                    @endif
                </dl>
            </x-filament::section>
        </div>
    </div>

    @if ($case->state === 'enrolled')
        <x-filament::section icon="heroicon-o-identification">
            <x-slot name="heading">{{ $case->pathway === 'employer' ? 'Teilnehmende des Betriebs' : 'Teilnehmerakte' }}</x-slot>
            <x-slot name="description">Eingeschrieben am {{ $case->enrolled_at?->format('d.m.Y') }}. Ab hier geht es in der Teilnehmerakte weiter: Vertrag, Eintritt, Durchführung, Abschluss, Verbleib.</x-slot>
            @if ($participants->isEmpty())
                <p class="text-sm text-gray-500">
                    @if ($case->pathway === 'employer')
                        Noch keine Teilnehmenden angelegt. Oben „Teilnehmer/in anlegen“ für jede beschäftigte Person.
                    @else
                        Keine Teilnehmerakte vorhanden (am Vorgang ist keine Person hinterlegt).
                    @endif
                </p>
            @else
                <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                    @foreach ($participants as $participant)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span><span class="font-medium">{{ $participant->displayName() }}</span> · {{ $participant->number }} · {{ $participant->stateLabel() }}</span>
                            @if ($canSeeFiles)
                                <a href="{{ \App\Filament\Resources\Participants\ParticipantResource::getUrl('view', ['record' => $participant]) }}" class="text-primary-600 hover:underline dark:text-primary-400">Akte öffnen</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @endif

    @can('documents')
        <x-filament::section icon="heroicon-o-document-text">
            <x-slot name="heading">Dokumente</x-slot>
            <x-slot name="description">Bildungsgutschein, Bewilligung, Vertrag und Schriftverkehr. Verschlüsselt gespeichert, jeder Abruf im Protokoll. Die Unterlagen gehen mit in die Teilnehmerakte.</x-slot>
            @livewire(\App\Livewire\DocumentList::class, ['lead' => $lead], key('documents-case-'.$case->id))
        </x-filament::section>
    @endcan
</x-filament-panels::page>
