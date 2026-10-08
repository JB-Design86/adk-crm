<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Vorlage für „E-Mail schreiben“ im Vorgang: Betreff und Text mit Platzhaltern,
 * auf Wunsch eine Datei als Anhang (private Ablage, nie öffentlich erreichbar).
 * Nur die Verwaltung pflegt Vorlagen, Änderungen stehen im Protokoll.
 */
class EmailTemplate extends Model
{
    use LogsActivity;

    public const DISK = 'local';

    public const DIRECTORY = 'email-templates';

    /** Platzhalter mit Erklärung für die Hilfe im Formular. */
    public const PLACEHOLDERS = [
        '{anrede}' => '„Sehr geehrte Frau Muster“ bzw. „Sehr geehrter Herr Muster“, ohne Ansprechperson „Sehr geehrte Damen und Herren“',
        '{vorname}' => 'Vorname der Ansprechperson',
        '{nachname}' => 'Nachname der Ansprechperson',
        '{firma}' => 'Name des Betriebs',
        '{absender}' => 'Ihr Name aus Microsoft 365',
    ];

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
        static::creating(function (EmailTemplate $template) {
            if (! $template->sort_order) {
                $template->sort_order = (int) static::max('sort_order') + 1;
            }
        });

        // Ersetzte oder entfernte Anhänge nicht als Dateileichen liegen lassen.
        static::updated(function (EmailTemplate $template) {
            $old = $template->getOriginal('attachment_path');

            if ($template->wasChanged('attachment_path') && filled($old)) {
                Storage::disk(self::DISK)->delete($old);
            }
        });

        static::deleted(function (EmailTemplate $template) {
            if (filled($template->attachment_path)) {
                Storage::disk(self::DISK)->delete($template->attachment_path);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('email_templates')
            ->logOnly(['name', 'subject', 'body', 'attachment_name', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function hasAttachment(): bool
    {
        return filled($this->attachment_path);
    }

    public function attachmentName(): ?string
    {
        return $this->hasAttachment() ? ($this->attachment_name ?: basename($this->attachment_path)) : null;
    }

    public static function placeholderHelp(): string
    {
        return 'Platzhalter: '.collect(self::PLACEHOLDERS)->map(fn (string $text, string $key) => "{$key} = {$text}")->join('; ').'.';
    }
}
