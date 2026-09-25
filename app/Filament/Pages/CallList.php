<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Services\LeadStatusService;
use App\Support\Adk;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Anrufliste: ein Betrieb nach dem anderen. Reihenfolge Priorität A, B, C,
 * dann älteste Wiedervorlage. Bedienung über die Tasten 0 bis 9, Enter speichert.
 */
class CallList extends Page
{
    protected string $view = 'filament.pages.call-list';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static string|UnitEnum|null $navigationGroup = 'Akquise';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Anrufliste';

    protected static ?string $title = 'Anrufliste';

    protected static ?string $slug = 'anrufliste';

    public ?int $leadId = null;

    public bool $onlyMine = true;

    /** @var list<int> in dieser Sitzung übersprungen */
    public array $skipped = [];

    public ?string $status = null;

    public string $note = '';

    public ?string $nextActionAt = null;

    /** @var array{date?: ?string, time?: ?string, type?: ?string} */
    public array $appointment = ['date' => null, 'time' => null, 'type' => 'phone'];

    public ?string $closeReason = null;

    /** @var array{first_name?: ?string, last_name?: ?string, email?: ?string} */
    public array $recipient = ['first_name' => null, 'last_name' => null, 'email' => null];

    public bool $confirmingObjection = false;

    public bool $crossSellingOpen = false;

    public ?string $crossSellingDate = null;

    /** Nach dem dritten Versuch: Vorgang ruhen lassen? */
    public ?int $restPromptLeadId = null;

    public static function canAccess(): bool
    {
        return Gate::allows('call_list');
    }

    public function mount(): void
    {
        $requested = request()->integer('vorgang');

        $this->leadId = $requested && Lead::open()->whereKey($requested)->exists()
            ? $requested
            : $this->queue()->value('leads.id');
    }

    /**
     * Warteschlange: offene Vorgänge mit Wiedervorlage heute, überfällig oder ohne Datum.
     * Privatpersonen ohne Einwilligung aus der Kaltakquise erscheinen nicht.
     */
    public function queue(): Builder
    {
        $priorityOrder = "CASE organizations.priority WHEN 'A' THEN 1 WHEN 'B' THEN 2 WHEN 'C' THEN 3 ELSE 4 END";
        $inbound = Adk::inboundChannels();

        return Lead::query()
            ->select('leads.*')
            ->leftJoin('organizations', 'organizations.id', '=', 'leads.organization_id')
            ->leftJoin('contacts', 'contacts.id', '=', 'leads.contact_id')
            ->whereNull('leads.closed_at')
            ->where('leads.status', '!=', 'handed_over')
            ->where(fn (Builder $q) => $q->whereNull('leads.next_action_at')->orWhereDate('leads.next_action_at', '<=', today()))
            ->where(fn (Builder $q) => $q->whereNotNull('organizations.phone_e164')->orWhereNotNull('contacts.phone_e164'))
            // Privatpersonen: nur mit Einwilligung (Datum und Nachweis) oder bei eingehender Anfrage
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $b) => $b->whereNotNull('leads.organization_id')->where(fn ($c) => $c->whereNull('contacts.is_private')->orWhere('contacts.is_private', false)))
                ->orWhereIn('leads.channel', $inbound)
                ->orWhere(fn (Builder $p) => $p->whereNotNull('contacts.phone_consent_at')->whereNotNull('contacts.phone_consent_proof')))
            ->when($this->onlyMine, fn (Builder $q) => $q->where(fn ($m) => $m->whereNull('leads.assigned_to')->orWhere('leads.assigned_to', auth()->id())))
            ->when($this->skipped, fn (Builder $q) => $q->whereNotIn('leads.id', $this->skipped))
            ->orderByRaw($priorityOrder)
            ->orderByRaw('leads.next_action_at IS NULL')
            ->orderBy('leads.next_action_at')
            ->orderBy('leads.id');
    }

    #[Computed]
    public function lead(): ?Lead
    {
        return $this->leadId
            ? Lead::with(['organization', 'contact', 'assignee', 'activities' => fn ($q) => $q->with('user')->limit(8)])->find($this->leadId)
            : null;
    }

    #[Computed]
    public function remaining(): int
    {
        return $this->queue()->count();
    }

    /** Taste gedrückt (0–9). */
    public function selectKey(string $key): void
    {
        if (! $this->lead || $this->restPromptLeadId) {
            return;
        }

        if ($key === config('adk.cross_selling_key')) {
            $this->toggleCrossSellingForm();

            return;
        }

        $status = Adk::statusForKey($key);

        if ($status === null) {
            return;
        }

        $this->status = $status;
        $this->confirmingObjection = false;
        $this->resetErrorBag();
    }

    public function toggleCrossSellingForm(): void
    {
        if ($this->lead->cross_selling) {
            // Merkmal entfernen braucht kein Datum.
            app(LeadStatusService::class)->toggleCrossSelling($this->lead, note: $this->note ?: null);
            Notification::make()->title('Cross-Selling JB Design entfernt')->success()->send();
            unset($this->lead);

            return;
        }

        $this->crossSellingOpen = ! $this->crossSellingOpen;
    }

    public function saveCrossSelling(): void
    {
        try {
            app(LeadStatusService::class)->toggleCrossSelling($this->lead, $this->crossSellingDate, $this->note ?: null);
        } catch (ValidationException $exception) {
            $this->addError('crossSellingDate', $exception->validator->errors()->first());

            return;
        }

        Notification::make()->title('Cross-Selling JB Design gesetzt')->success()->send();
        $this->crossSellingOpen = false;
        $this->crossSellingDate = null;
        unset($this->lead);
    }

    /** Enter: speichern und zum nächsten Betrieb. */
    public function save(): void
    {
        Gate::authorize('leads.edit');

        if ($this->restPromptLeadId) {
            return;
        }

        if (! $this->lead || ! $this->status) {
            $this->addError('status', 'Bitte wählen Sie mit den Tasten 1 bis 9 einen Status.');

            return;
        }

        if (config("adk.statuses.{$this->status}.blocklist") && ! $this->confirmingObjection) {
            // Sicherheitsabfrage vor dem Speichern.
            $this->confirmingObjection = true;

            return;
        }

        $this->persist(confirmed: $this->confirmingObjection);
    }

    public function confirmObjection(): void
    {
        $this->persist(confirmed: true);
    }

    public function cancelObjection(): void
    {
        $this->confirmingObjection = false;
        $this->status = null;
    }

    public function rest(bool $rest): void
    {
        if ($rest && $this->restPromptLeadId) {
            app(LeadStatusService::class)->rest(Lead::findOrFail($this->restPromptLeadId));
            Notification::make()->title('Vorgang ruht')->success()->send();
        }

        $this->restPromptLeadId = null;
        $this->next();
    }

    public function skip(): void
    {
        if ($this->leadId) {
            $this->skipped[] = $this->leadId;
        }

        $this->next();
    }

    public function updatedOnlyMine(): void
    {
        $this->skipped = [];
        $this->next();
    }

    private function persist(bool $confirmed): void
    {
        $lead = $this->lead;

        try {
            $result = app(LeadStatusService::class)->apply($lead, $this->status, [
                'note' => $this->note,
                'next_action_at' => $this->nextActionAt,
                'appointment' => $this->appointment,
                'close_reason' => $this->closeReason,
                'confirmed' => $confirmed,
                'contact' => $this->recipient,
            ], asCall: true);
        } catch (ValidationException $exception) {
            $this->confirmingObjection = false;

            foreach ($exception->validator->errors()->messages() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }

            return;
        }

        Notification::make()
            ->title($lead->displayName().': '.Adk::statusLabel($this->status))
            ->success()
            ->send();

        if ($result->suggestRest) {
            $this->resetForm();
            $this->restPromptLeadId = $lead->id;

            return;
        }

        $this->next();
    }

    private function next(): void
    {
        $this->resetForm();
        $this->leadId = $this->queue()->value('leads.id');
        unset($this->lead, $this->remaining);
        $this->dispatch('call-list-next');
    }

    private function resetForm(): void
    {
        $this->reset(['status', 'note', 'nextActionAt', 'closeReason', 'confirmingObjection', 'crossSellingOpen', 'crossSellingDate']);
        $this->appointment = ['date' => null, 'time' => null, 'type' => 'phone'];
        $this->recipient = ['first_name' => null, 'last_name' => null, 'email' => null];
        $this->resetErrorBag();
    }

    public function leadUrl(): ?string
    {
        return $this->lead ? LeadResource::getUrl('view', ['record' => $this->lead]) : null;
    }

    /** @return array<string, array{key: string, label: string}> */
    public function keyLegend(): array
    {
        $legend = [];

        foreach (Adk::statuses() as $status => $definition) {
            if ($definition['key'] !== null) {
                $legend[$status] = ['key' => $definition['key'], 'label' => $definition['label']];
            }
        }

        uasort($legend, fn ($a, $b) => $a['key'] <=> $b['key']);
        $legend['cross_selling'] = ['key' => config('adk.cross_selling_key'), 'label' => 'Cross-Selling JB Design'];

        return $legend;
    }
}
