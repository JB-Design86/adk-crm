<?php

namespace App\Models;

use App\Support\Adk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dokument am Vorgang (Anfrage, Förderfall) oder an der Teilnehmerakte.
 * Der Inhalt liegt verschlüsselt auf dem Datenträger „documents“ (siehe DocumentService).
 */
class Document extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path', 'sha256'];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'size' => 'integer',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isHealth(): bool
    {
        return Adk::isHealthCategory($this->category);
    }

    public function categoryLabel(): string
    {
        return Adk::documentCategoryLabel($this->category) ?? '–';
    }

    /** Darf diese Person das Dokument sehen? Gesundheitsangaben nur mit health.view. */
    public function isVisibleTo(?User $user): bool
    {
        return $user !== null
            && $user->hasPermission('documents')
            && (! $this->isHealth() || $user->hasPermission('health.view'))
            && ($this->participant_id === null || $user->hasPermission('participants'));
    }

    /** Löschen: Verwaltung, oder wer hochgeladen hat, am selben Tag (Versehen). */
    public function isDeletableBy(?User $user): bool
    {
        return $user !== null && $this->isVisibleTo($user) && (
            $user->hasPermission('documents.delete')
            || ($this->uploaded_by === $user->id && $this->created_at->isToday())
        );
    }

    /** Im Browser anzeigen statt herunterladen (PDF und Bilder). */
    public function isInlineViewable(): bool
    {
        return in_array($this->mime_type, ['application/pdf', 'image/jpeg', 'image/png'], true);
    }

    public function sizeLabel(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1, ',', '.').' MB'
            : max(1, (int) round($this->size / 1024)).' KB';
    }
}
