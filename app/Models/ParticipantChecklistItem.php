<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Punkt der Checkliste in der Teilnehmerakte (Lastenheft 7.1), von der Verwaltung pflegbar.
 * key markiert Punkte, die das CRM selbst abhakt (funder_confirmed bei der Einschreibung).
 */
class ParticipantChecklistItem extends Model
{
    use LogsActivity;

    protected $guarded = ['id', 'key'];

    protected $attributes = [
        'is_active' => true,
        'repeatable' => false,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'repeatable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ParticipantChecklistItem $item) {
            if (! $item->sort_order) {
                $item->sort_order = (int) static::max('sort_order') + 1;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('checklist_items')
            ->logOnly(['phase', 'name', 'instructions', 'document_category', 'repeatable', 'sort_order', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function checks(): HasMany
    {
        return $this->hasMany(ParticipantCheck::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function phaseLabel(): string
    {
        return config("adk.checklist_phases.{$this->phase}") ?? $this->phase;
    }
}
