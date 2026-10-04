<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Erledigter Punkt der Checkliste: Datum, Notiz, wer, und das zugehörige Dokument.
 */
class ParticipantCheck extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'done_on' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('participant_checks')
            ->logOnly(['participant_id', 'participant_checklist_item_id', 'done_on', 'note', 'document_id'])
            ->dontLogEmptyChanges();
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ParticipantChecklistItem::class, 'participant_checklist_item_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
