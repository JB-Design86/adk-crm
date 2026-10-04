<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Schritt eines Förderwegs (Stufe 3). Von der Verwaltung pflegbar,
 * mit Erklärtext „Was ist jetzt von unserer Seite zu tun?“.
 */
class FundingStep extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected $attributes = [
        'is_active' => true,
        'follow_up_days' => 5,
        'calendar_days' => false,
        'can_fail' => false,
        'requires_document' => false,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'calendar_days' => 'boolean',
            'can_fail' => 'boolean',
            'requires_document' => 'boolean',
            'follow_up_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FundingStep $step) {
            if (! $step->sort_order) {
                $step->sort_order = (int) static::where('pathway', $step->pathway)->max('sort_order') + 1;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('funding_steps')
            ->logOnly(['pathway', 'name', 'instructions', 'default_party', 'follow_up_days', 'calendar_days', 'can_fail', 'document_category', 'requires_document', 'sort_order', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function completions(): HasMany
    {
        return $this->hasMany(FundingCaseStep::class);
    }

    public function scopeForPathway(Builder $query, string $pathway): Builder
    {
        return $query->where('pathway', $pathway)->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    public function pathwayLabel(): string
    {
        return config("adk.funding_pathways.{$this->pathway}.label", $this->pathway);
    }

    public function followUpLabel(): string
    {
        return $this->follow_up_days.' '.($this->calendar_days ? 'Kalendertage' : 'Arbeitstage');
    }
}
