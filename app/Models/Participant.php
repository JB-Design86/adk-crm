<?php

namespace App\Models;

use App\Support\Adk;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Teilnehmerakte (Stufe 4). Entsteht erst mit „Einschreibung bestätigt“, also wenn die Person
 * offiziell eingeschrieben ist und der Kostenträger bestätigt hat. Name, Telefon und E-Mail
 * stehen am Kontakt; hier stehen Vertragsdaten, Kurs, Checkliste, Abschluss und Verbleib.
 */
class Participant extends Model
{
    use LogsActivity;

    protected $guarded = ['id', 'number'];

    protected $attributes = [
        'state' => 'registered',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'course_starts_on' => 'date',
            'course_ends_on' => 'date',
            'left_on' => 'date',
            'follow_up_on' => 'date',
            'placement_recorded_on' => 'date',
            'accommodation_notes' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // Teilnehmernummer TN-2026-001, fortlaufend je Jahr.
        static::creating(function (Participant $participant) {
            $prefix = 'TN-'.now()->format('Y').'-';
            $last = static::where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');
            $next = (int) substr((string) $last, strlen($prefix)) + 1;
            $participant->number = $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('participants')
            // Nachteilsausgleich hat Gesundheitsbezug: nicht ins Protokoll.
            ->logExcept(['accommodation_notes', 'created_at', 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function fundingCase(): BelongsTo
    {
        return $this->belongsTo(FundingCase::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(ParticipantCheck::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereIn('state', ['registered', 'active']);
    }

    public function displayName(): string
    {
        return $this->contact?->fullName() ?? $this->number;
    }

    public function stateLabel(): string
    {
        return Adk::participantStateLabel($this->state) ?? $this->state;
    }

    public function isFinished(): bool
    {
        return in_array($this->state, ['completed', 'dropped'], true);
    }

    /** Ende der Maßnahme: Austritt, sonst geplantes Kursende. */
    public function measureEndedOn(): ?CarbonImmutable
    {
        $date = $this->left_on ?? $this->course_ends_on;

        return $date ? CarbonImmutable::parse($date) : null;
    }

    /** Ab wann die Akte gelöscht wird: zehn Jahre ab Ende des Jahres, in dem die Maßnahme endete (A-22). */
    public function retentionEndsOn(): ?CarbonImmutable
    {
        return $this->measureEndedOn()?->endOfYear()->addYears(config('adk.retention.participant_years'))->endOfDay();
    }

    /**
     * Checkliste nach Phasen mit dem Stand dieser Akte.
     *
     * @return Collection<string, Collection<int, array{item: ParticipantChecklistItem, checks: Collection<int, ParticipantCheck>}>>
     */
    public function checklist(): Collection
    {
        $checks = $this->checks()->with(['user', 'document'])->orderBy('done_on')->get()->groupBy('participant_checklist_item_id');

        return ParticipantChecklistItem::query()->active()->get()
            ->map(fn (ParticipantChecklistItem $item) => ['item' => $item, 'checks' => $checks->get($item->id, collect())])
            ->groupBy(fn (array $row) => $row['item']->phase);
    }

    /** @return array{done: int, total: int} ohne wiederholbare Punkte */
    public function progress(): array
    {
        $items = ParticipantChecklistItem::query()->active()->where('repeatable', false)->pluck('id');
        $done = $this->checks()->whereIn('participant_checklist_item_id', $items)->distinct()->count('participant_checklist_item_id');

        return ['done' => $done, 'total' => $items->count()];
    }
}
