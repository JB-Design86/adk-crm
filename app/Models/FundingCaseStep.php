<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Erledigter Schritt eines Förderfalls: Datum, Ergebnis, handelnde Stelle, Notiz.
 */
class FundingCaseStep extends Model
{
    use LogsActivity;

    public const RESULTS = [
        'done' => 'erledigt',
        'rejected' => 'abgelehnt',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'completed_on' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('funding_cases')
            ->logOnly(['funding_case_id', 'funding_step_id', 'completed_on', 'result', 'party', 'note'])
            ->dontLogEmptyChanges();
    }

    public function fundingCase(): BelongsTo
    {
        return $this->belongsTo(FundingCase::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(FundingStep::class, 'funding_step_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resultLabel(): string
    {
        return self::RESULTS[$this->result] ?? $this->result;
    }

    public function partyLabel(): ?string
    {
        return $this->party ? config("adk.funding_parties.{$this->party}", $this->party) : null;
    }
}
