<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Förderfall („fester Interessent“, Stufe 3): vom Übergeben an den Förderweg
 * bis zur bestätigten Einschreibung. Erst dann folgt die Teilnehmerakte (Stufe 4).
 */
class FundingCase extends Model
{
    use LogsActivity;

    public const STATES = [
        'open' => 'läuft',
        'enrolled' => 'eingeschrieben',
        'cancelled' => 'abgebrochen',
    ];

    protected $guarded = ['id'];

    protected $attributes = [
        'state' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'voucher_valid_until' => 'date',
            'enrolled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('funding_cases')
            ->logAll()
            ->logExcept(['created_at', 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function completedSteps(): HasMany
    {
        return $this->hasMany(FundingCaseStep::class)->orderBy('completed_on')->orderBy('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('state', 'open');
    }

    /** @return Collection<int, FundingStep> aktive Schritte des Förderwegs in Reihenfolge */
    public function steps(): Collection
    {
        return FundingStep::query()->forPathway($this->pathway)->get();
    }

    /** Erster aktiver Schritt ohne Eintrag, oder null, wenn alles erledigt ist. */
    public function currentStep(): ?FundingStep
    {
        $done = $this->completedSteps()->pluck('funding_step_id')->all();

        return $this->steps()->first(fn (FundingStep $step) => ! in_array($step->id, $done, true));
    }

    /**
     * Pflichtunterlagen, die noch fehlen (Schritte mit „Unterlage Pflicht“), als Dokumentart => Schritt.
     *
     * @return array<string, string>
     */
    public function missingDocuments(): array
    {
        $present = $this->lead->documents()->distinct()->pluck('category')->all();

        return $this->steps()
            ->filter(fn (FundingStep $step) => $step->requires_document && $step->document_category && ! in_array($step->document_category, $present, true))
            ->mapWithKeys(fn (FundingStep $step) => [$step->document_category => $step->name])
            ->all();
    }

    public function allStepsDone(): bool
    {
        return $this->currentStep() === null;
    }

    public function isOpen(): bool
    {
        return $this->state === 'open';
    }

    public function pathwayLabel(): string
    {
        return config("adk.funding_pathways.{$this->pathway}.label", $this->pathway);
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state] ?? $this->state;
    }

    /** Fortschritt als „3 von 9“. */
    public function progressLabel(): string
    {
        $steps = $this->steps();
        $done = $this->completedSteps()->whereIn('funding_step_id', $steps->pluck('id'))->count();

        return "{$done} von {$steps->count()}";
    }
}
