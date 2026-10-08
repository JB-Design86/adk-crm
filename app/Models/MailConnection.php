<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
