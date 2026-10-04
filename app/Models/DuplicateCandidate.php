<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dublettenverdacht: subject (neuer bzw. später angelegter Datensatz) ähnelt match (vorhandener).
 */
class DuplicateCandidate extends Model
{
    public const TYPES = [
        'organization' => Organization::class,
        'contact' => Contact::class,
    ];

    public const STATES = [
        'open' => 'offen',
        'merged' => 'zusammengeführt',
        'rejected' => 'verschieden',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'score' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('state', 'open');
    }

    public function importLog(): BelongsTo
    {
        return $this->belongsTo(ImportLog::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function subjectRecord(): ?Model
    {
        return (self::TYPES[$this->type])::find($this->subject_id);
    }

    public function matchRecord(): ?Model
    {
        return (self::TYPES[$this->type])::find($this->match_id);
    }

    /** Offener Verdacht für diese Organisation bzw. diesen Kontakt? */
    public static function isFlagged(Model $record): bool
    {
        $type = array_search($record::class, self::TYPES, true);

        return $type !== false && static::open()->where('type', $type)->where('subject_id', $record->getKey())->exists();
    }

    /** Vorgang mit offenem Verdacht an Organisation oder Kontakt. */
    public static function isLeadFlagged(Lead $lead): bool
    {
        return static::open()->where(fn (Builder $q) => $q
            ->where(fn (Builder $o) => $o->where('type', 'organization')->where('subject_id', $lead->organization_id))
            ->orWhere(fn (Builder $c) => $c->where('type', 'contact')->where('subject_id', $lead->contact_id)))
            ->exists();
    }
}
