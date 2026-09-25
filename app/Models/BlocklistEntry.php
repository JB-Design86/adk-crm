<?php

namespace App\Models;

use App\Support\Normalizer;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Sperrliste (Werbewiderspruch). Unbefristet, vom Löschlauf ausgenommen.
 */
class BlocklistEntry extends Model
{
    use HasFactory, LogsActivity;

    protected $guarded = ['id', 'company_name_normalized'];

    protected function casts(): array
    {
        return [
            'blocked_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (BlocklistEntry $entry) {
            $entry->phone_e164 = $entry->phone_e164 ? (Phone::normalize($entry->phone_e164) ?? $entry->phone_e164) : null;
            $entry->email = Normalizer::email($entry->email);
            $entry->company_name_normalized = Normalizer::companyName($entry->company_name);
            $entry->postal_code = Normalizer::postalCode($entry->postal_code);
            $entry->blocked_on ??= today();
            $entry->created_by ??= auth()->id();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('blocklist')
            ->logOnly(['phone_e164', 'email', 'company_name', 'postal_code', 'blocked_on', 'reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Prüft Telefon (E.164), E-Mail oder Firmenname mit PLZ gegen die Sperrliste.
     */
    public static function matches(?string $phone = null, ?string $email = null, ?string $companyName = null, ?string $postalCode = null): bool
    {
        return self::findMatch($phone, $email, $companyName, $postalCode) !== null;
    }

    public static function findMatch(?string $phone = null, ?string $email = null, ?string $companyName = null, ?string $postalCode = null): ?self
    {
        $phone = $phone ? (str_starts_with($phone, '+') ? $phone : Phone::normalize($phone)) : null;
        $email = Normalizer::email($email);
        $name = Normalizer::companyName($companyName);
        $postalCode = Normalizer::postalCode($postalCode);

        if (! $phone && ! $email && ! ($name && $postalCode)) {
            return null;
        }

        return self::query()
            ->where(function ($query) use ($phone, $email, $name, $postalCode) {
                if ($phone) {
                    $query->orWhere('phone_e164', $phone);
                }

                if ($email) {
                    $query->orWhere('email', $email);
                }

                if ($name && $postalCode) {
                    $query->orWhere(fn ($q) => $q->where('company_name_normalized', $name)->where('postal_code', $postalCode));
                }
            })
            ->first();
    }
}
