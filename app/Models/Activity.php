<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Aktivität an einem Vorgang: Anruf, E-Mail, Notiz, Statuswechsel, Termin.
 */
class Activity extends Model
{
    use HasFactory, LogsActivity;

    public const TYPES = [
        'call' => 'Anruf',
        'email' => 'E-Mail',
        'note' => 'Notiz',
        'status_change' => 'Statuswechsel',
        'appointment' => 'Termin',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Activity $activity) {
            $activity->occurred_at ??= now();
            $activity->user_id ??= auth()->id();
        });

        static::created(function (Activity $activity) {
            $activity->lead?->forceFill(['last_contact_at' => $activity->occurred_at])->saveQuietly();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('activities')
            ->logOnly(['lead_id', 'type', 'outcome', 'status_from', 'status_to', 'body', 'occurred_at'])
            ->dontLogEmptyChanges();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
