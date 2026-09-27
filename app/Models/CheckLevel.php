<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Prüfstufe der Leadliste (Branchenmatrix). Von der Verwaltung frei
 * anlegbar, umbenennbar, sortierbar und abschaltbar.
 */
class CheckLevel extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CheckLevel $level) {
            if (! $level->sort_order) {
                $level->sort_order = (int) static::max('sort_order') + 1;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('check_levels')
            ->logOnly(['name', 'description', 'sort_order', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function results(): HasMany
    {
        return $this->hasMany(OrganizationCheck::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** @return Collection<int, CheckLevel> aktive Prüfstufen in Reihenfolge */
    public static function activeOrdered(): Collection
    {
        return static::query()->active()->ordered()->get();
    }
}
