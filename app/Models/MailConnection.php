<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Verbindung eines Benutzers mit seinem Microsoft-365-Postfach (E-Mail aus dem Vorgang).
 * Tokens verschlüsselt. Die Signatur hängt das CRM an jede E-Mail an.
 */
class MailConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->subSeconds(60)->isPast();
    }

    /**
     * Signatur für die E-Mail als HTML, bereinigt. Absätze mit kleinem Abstand darunter, wie sie auch
     * im Editor erscheinen, leere Absätze als Leerzeile. Tabellen aus dem HTML-Code (z. B. für bündige
     * Telefonnummern) bleiben erhalten. Ohne HTML-Signatur die bisherige Text-Signatur. Null, wenn keine da ist.
     */
    public function signatureHtml(): ?string
    {
        if (filled(trim(strip_tags((string) $this->signature_html)))) {
            $html = self::cleanSignature((string) $this->signature_html);
            $html = preg_replace('#<p>\s*</p>#', '<p style="margin:0">&nbsp;</p>', $html);

            return str_replace('<p>', '<p style="margin:0 0 12px">', $html);
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $this->signature));

        return $text !== '' ? nl2br(e($text), false) : null;
    }

    /** HTML der Signatur bereinigt, ohne Bilder (das Logo kommt aus dem eigenen Feld). */
    public static function cleanSignature(string $html): string
    {
        return preg_replace('#<img\b[^>]*>#i', '', Str::sanitizeHtml($html));
    }

    public const LOGO_DISK = 'local';

    public const LOGO_DIRECTORY = 'signaturen';

    /**
     * Logo unter der Signatur, als eingebettetes Bild (Inline-Anhang) für Graph.
     *
     * @return array{name: string, content_type: string, contents: string}|null
     */
    public function logo(): ?array
    {
        $path = $this->signature_logo_path;
        $disk = Storage::disk(self::LOGO_DISK);

        if (blank($path) || ! $disk->exists($path)) {
            return null;
        }

        return [
            'name' => basename($path),
            'content_type' => $disk->mimeType($path) ?: 'image/png',
            'contents' => $disk->get($path),
        ];
    }

    public function logoWidth(): int
    {
        return min(600, max(60, (int) ($this->signature_logo_width ?: 200)));
    }
}
