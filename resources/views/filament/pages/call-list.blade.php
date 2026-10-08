@php
    $lead = $this->lead;
    $organization = $lead?->organization;
    $contact = $lead?->contact;
    $blockReason = $lead?->callBlockReason();
    $follow = $status ? config("adk.statuses.{$status}.follow_up") : null;
@endphp

<x-filament-panels::page>
    <div
        x-data="{
            focusNote() { this.$nextTick(() => this.$refs.note?.focus()) },
            isTyping(target) { return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) },
        }"
        x-init="focusNote()"
        x-on:call-list-next.window="focusNote()"
        x-on:keydown.window="
            if ($event.ctrlKey || $event.metaKey) return;
            {{-- Im Formular „E-Mail schreiben“ gelten keine Tasten der Anrufliste. --}}
            if ($event.target.closest?.('.fi-modal')) return;
            const digit = /^[0-9]$/.test($event.key) ? $event.key : ($event.code?.startsWith('Digit') ? $event.code.slice(5) : null);
            if (digit !== null && ($event.altKey || ! isTyping($event.target))) {
                $event.preventDefault();
                $wire.selectKey(digit);
                return;
            }
            const otherTextarea = $event.target.tagName === 'TEXTAREA' && $event.target !== $refs.note;
            if ($event.key === 'Enter' && ! $event.shiftKey && ! otherTextarea && ! ['BUTTON', 'SELECT', 'A'].includes($event.target.tagName)) {
                $event.preventDefault();
                $wire.save();
                return;
            }
            if ($event.key === 'Escape' && isTyping($event.target)) { $event.target.blur(); }
        "
        class="space-y-6"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm text-gray-600 dark:text-gray-400">
                Noch <strong>{{ $this->remaining }}</strong> Vorgänge in der Liste.
                Reihenfolge: fällige Rückrufe mit Uhrzeit, dann Priorität A, B, C, dann älteste Wiedervorlage.
            </div>
            <div class="flex flex-wrap items-center gap-4">
                @if (count($this->importOptions) > 0)
                    <label class="flex items-center gap-2 text-sm">
                        <span>Liste</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="importLogId">
                                <option value="">alle Vorgänge</option>
                                @foreach ($this->importOptions as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                @endif
                <label class="flex items-center gap-2 text-sm">
                    <x-filament::input.checkbox wire:model.live="onlyMine" />
                    nur meine und nicht zugewiesene
                </label>
                @if ($lead)
                    <x-filament::button color="gray" size="sm" wire:click="skip" icon="heroicon-o-forward">Überspringen</x-filament::button>
                @endif
            </div>
        </div>

        @if (! $lead)
            <x-filament::section>
                <p class="text-center text-gray-600 dark:text-gray-400">Für heute ist die Anrufliste leer.</p>
            </x-filament::section>
        @else
            @if ($restPromptLeadId)
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/40 dark:bg-amber-500/10">
                    <p class="font-semibold">Drei Versuche ohne Erfolg. Vorgang ruhen lassen?</p>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Die Wiedervorlage wird um {{ config('adk.not_reached_rest_months') }} Monate verschoben.</p>
                    <div class="mt-3 flex gap-3">
                        <x-filament::button wire:click="rest(true)" color="warning">Ja, ruhen lassen</x-filament::button>
                        <x-filament::button wire:click="rest(false)" color="gray">Nein, weiter</x-filament::button>
                    </div>
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                {{-- Firmendaten --}}
                <x-filament::section class="lg:col-span-2">
                    <x-slot name="heading">
                        <span class="text-xl">{{ $lead->displayName() }}</span>
                    </x-slot>
                    <x-slot name="afterHeader">
                        <div class="flex items-center gap-2">
                            @if ($lead->isCallbackDue())
                                <x-filament::badge color="danger" icon="heroicon-o-clock">Rückruf {{ $lead->next_action_time }} Uhr</x-filament::badge>
                            @endif
                            @if ($organization?->priority)
                                <x-filament::badge color="gray">Priorität {{ $organization->priority }}</x-filament::badge>
                            @endif
                            <x-filament::badge :color="\App\Support\Adk::statusColor($lead->status)">{{ \App\Support\Adk::statusLabel($lead->status) }}</x-filament::badge>
                            @if ($lead->cross_selling)
                                <x-filament::badge color="info" icon="heroicon-o-sparkles">Cross-Selling</x-filament::badge>
                            @endif
                        </div>
                    </x-slot>

                    @if ($blockReason)
                        <div class="mb-4 flex items-start gap-3 rounded-lg border border-red-300 bg-red-50 p-3 text-red-800 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-300">
                            <x-filament::icon icon="heroicon-o-no-symbol" class="h-6 w-6 shrink-0" />
                            <div>
                                <p class="font-semibold">Kein Anruf möglich</p>
                                <p class="text-sm">{{ $blockReason }}</p>
                            </div>
                        </div>
                    @endif

                    <div class="mb-4">
                        @if ($lead->phoneE164() && ! $blockReason)
                            <a href="tel:{{ $lead->phoneE164() }}" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-lg font-semibold text-white hover:bg-primary-500">
                                <x-filament::icon icon="heroicon-o-phone" class="h-5 w-5" />
                                {{ $lead->phoneDisplay() }}
                            </a>
                            @if ($this->sipgateAvailable())
                                <x-filament::button wire:click="callViaSipgate" color="gray" icon="heroicon-o-phone-arrow-up-right" class="ml-2 align-middle" title="Zuerst klingelt Ihr unter „Telefonie“ gewähltes Gerät, danach wählt sipgate die Nummer.">
                                    Über sipgate anrufen
                                </x-filament::button>
                            @endif
                        @elseif ($lead->phoneDisplay())
                            <span class="text-lg text-gray-500 line-through">{{ $lead->phoneDisplay() }}</span>
                        @endif
                        <span class="ml-2 align-middle">{{ $this->sendEmailAction }}</span>
                    </div>

                    <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        @if ($organization)
                            <div><dt class="text-gray-500">Branche</dt><dd>{{ $organization->industry ?? '–' }} @if ($organization->wz_code)<span class="text-gray-500">(WZ {{ $organization->wz_code }})</span>@endif</dd></div>
                            <div><dt class="text-gray-500">Anschrift</dt><dd>{{ $organization->street }}, {{ $organization->postal_code }} {{ $organization->city }}</dd></div>
                            <div><dt class="text-gray-500">Mitarbeitende</dt><dd>{{ $organization->employee_count ?? '–' }} @if ($organization->is_training_company) · Ausbildungsbetrieb @endif</dd></div>
                            <div><dt class="text-gray-500">Website</dt><dd>{{ $organization->website ?? '–' }}</dd></div>
                            <div><dt class="text-gray-500">E-Mail</dt><dd>{{ $organization->email ?? '–' }}</dd></div>
                            <div><dt class="text-gray-500">Quelle</dt><dd>{{ $organization->source }} ({{ $organization->retrieved_at?->format('d.m.Y') }})@if ($organization->source_url) · <a href="{{ $organization->source_url }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">Fundstelle</a>@endif</dd></div>
                        @endif
                        @if ($contact)
                            <div><dt class="text-gray-500">Ansprechpartner</dt><dd>{{ $contact->fullName() }} @if ($contact->position)<span class="text-gray-500">· {{ $contact->position }}</span>@endif</dd></div>
                            <div><dt class="text-gray-500">Kontakt</dt><dd>{{ $contact->phone_display ?? '' }} {{ $contact->email ?? '' }}</dd></div>
                        @endif
                        <div><dt class="text-gray-500">Zielgruppe / Kanal</dt><dd>{{ \App\Support\Adk::targetGroupLabel($lead->target_group) }} · {{ \App\Support\Adk::channelLabel($lead->channel) }}</dd></div>
                        <div><dt class="text-gray-500">Versuche / Wiedervorlage</dt><dd>{{ $lead->call_attempts }} · {{ $lead->nextActionLabel() ?? 'noch keine' }}</dd></div>
                    </dl>

                    <div class="mt-4 text-sm">
                        <a href="{{ $this->leadUrl() }}" target="_blank" class="text-primary-600 hover:underline dark:text-primary-400">Vorgang öffnen</a>
                    </div>
                </x-filament::section>

                {{-- Tasten --}}
                <x-filament::section>
                    <x-slot name="heading">Tasten</x-slot>
                    <x-slot name="description">Taste wählt den Status (im Notizfeld mit Alt), Enter speichert und springt weiter.</x-slot>
                    <ul class="space-y-1 text-sm">
                        @foreach ($this->keyLegend() as $key => $entry)
                            <li>
                                <button type="button" wire:click="selectKey('{{ $entry['key'] }}')" title="{{ $entry['help'] }}"
                                    @class([
                                        'flex w-full items-center gap-3 rounded-md px-2 py-1 text-left hover:bg-gray-100 dark:hover:bg-white/5',
                                        'bg-primary-50 font-semibold ring-1 ring-primary-500 dark:bg-primary-500/10' => $status === $key || ($key === 'cross_selling' && $crossSellingOpen),
                                    ])>
                                    <kbd class="inline-flex h-6 w-6 items-center justify-center rounded border border-gray-300 bg-white font-mono text-xs dark:border-white/20 dark:bg-white/5">{{ $entry['key'] }}</kbd>
                                    <span>{{ $entry['label'] }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            </div>

            {{-- Erfassung --}}
            <x-filament::section>
                <x-slot name="heading">
                    Gespräch erfassen
                    @if ($status)
                        · <span class="text-primary-600 dark:text-primary-400">{{ \App\Support\Adk::statusLabel($status) }}</span>
                    @endif
                </x-slot>

                <div class="space-y-4">
                    <div>
                        <label for="call-note" class="text-sm font-medium">Notiz</label>
                        <textarea id="call-note" x-ref="note" wire:model="note" rows="3"
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5"
                            placeholder="Gesprächsnotiz (keine medizinischen Angaben). Enter speichert, Umschalt+Enter neue Zeile."></textarea>
                    </div>

                    @if ($crossSellingOpen)
                        <div class="flex flex-wrap items-end gap-3 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                            <div>
                                <label class="text-sm font-medium">Wiedervorlage Cross-Selling JB Design</label>
                                <input type="date" wire:model="crossSellingDate" min="{{ today()->toDateString() }}" class="mt-1 block rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                                @error('crossSellingDate') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                            </div>
                            <x-filament::button wire:click="saveCrossSelling" color="info" size="sm">Merkmal setzen</x-filament::button>
                        </div>
                    @endif

                    @if (\App\Services\LeadStatusService::hasFollowUpDate($status))
                        <div wire:key="follow-up-{{ $status }}">
                            <div class="flex flex-wrap gap-3">
                                <div>
                                    <label for="call-next-action-at" class="text-sm font-medium">Wiedervorlage am{{ $follow === 'required' ? ' (Pflicht)' : '' }}</label>
                                    <input id="call-next-action-at" type="date" wire:model="nextActionAt" min="{{ today()->toDateString() }}" @if ($follow === 'required') x-init="$el.focus()" @endif class="mt-1 block rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                                    @error('next_action_at') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="call-next-action-time" class="text-sm font-medium">Uhrzeit (optional)</label>
                                    <input id="call-next-action-time" type="time" wire:model="nextActionTime" class="mt-1 block rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                                    @error('next_action_time') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                @if ($follow === 'working_days')
                                    Vorschlag: in {{ config("adk.statuses.{$status}.days") }} Arbeitstagen. Bei Bedarf ändern.
                                @endif
                                Uhrzeit für einen Rückruf zu einer festen Zeit: Das CRM erinnert Sie zur Uhrzeit.
                            </p>
                        </div>
                    @endif

                    @if ($follow === 'appointment')
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="text-sm font-medium">Termin am</label>
                                <input type="date" wire:model="appointment.date" min="{{ today()->toDateString() }}" x-init="$el.focus()" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                                @error('appointment.date') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="text-sm font-medium">Uhrzeit</label>
                                <input type="time" wire:model="appointment.time" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                                @error('appointment.time') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="text-sm font-medium">Art</label>
                                <select wire:model="appointment.type" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                                    @foreach (config('adk.appointment_types') as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('appointment.type') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endif

                    @if ($status && config("adk.statuses.{$status}.requires_reason"))
                        <div>
                            <label class="text-sm font-medium">Grund</label>
                            <select wire:model="closeReason" x-init="$el.focus()" class="mt-1 block rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                                <option value="">bitte wählen</option>
                                @foreach (config('adk.wrong_data_reasons') as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('close_reason') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($status === 'documents_sent' && ! $contact)
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="text-sm font-medium">Vorname Empfänger</label>
                                <input type="text" wire:model="recipient.first_name" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                            </div>
                            <div>
                                <label class="text-sm font-medium">Nachname Empfänger (Pflicht)</label>
                                <input type="text" wire:model="recipient.last_name" x-init="$el.focus()" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                                @error('contact.last_name') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="text-sm font-medium">E-Mail Empfänger</label>
                                <input type="email" wire:model="recipient.email" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" />
                            </div>
                        </div>
                    @endif

                    @if ($confirmingObjection)
                        <div class="rounded-lg border border-red-300 bg-red-50 p-4 dark:border-red-500/40 dark:bg-red-500/10">
                            <p class="font-semibold text-red-800 dark:text-red-300">Werbewiderspruch speichern?</p>
                            <p class="text-sm text-red-800 dark:text-red-300">Der Vorgang wird geschlossen. Telefon, E-Mail und Firma (Name und PLZ) kommen dauerhaft auf die Sperrliste.</p>
                            <div class="mt-3 flex gap-3">
                                <x-filament::button wire:click="confirmObjection" color="danger">Ja, auf die Sperrliste</x-filament::button>
                                <x-filament::button wire:click="cancelObjection" color="gray">Abbrechen</x-filament::button>
                            </div>
                        </div>
                    @endif

                    @error('status') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror

                    @unless ($confirmingObjection)
                        <x-filament::button wire:click="save" icon="heroicon-o-check" :disabled="! $status">
                            Speichern und weiter (Enter)
                        </x-filament::button>
                    @endunless
                </div>
            </x-filament::section>

            {{-- Bisherige Aktivitäten --}}
            <x-filament::section collapsible>
                <x-slot name="heading">Bisherige Aktivitäten</x-slot>
                @forelse ($lead->activities as $activity)
                    <div class="flex gap-4 border-b border-gray-100 py-2 text-sm last:border-0 dark:border-white/5">
                        <div class="w-32 shrink-0 text-gray-500">{{ $activity->occurred_at->format('d.m.Y H:i') }}</div>
                        <div class="w-40 shrink-0">
                            {{ $activity->typeLabel() }}
                            @if ($activity->status_to)
                                <span class="text-gray-500">· {{ \App\Support\Adk::statusLabel($activity->status_to) }}</span>
                            @endif
                        </div>
                        <div class="grow whitespace-pre-line">{{ $activity->body }}</div>
                        <div class="w-32 shrink-0 text-right text-gray-500">{{ $activity->user?->name }}</div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Noch keine Aktivitäten.</p>
                @endforelse
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
