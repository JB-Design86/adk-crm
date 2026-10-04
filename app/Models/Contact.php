<?php

namespace App\Models;

use App\Support\Normalizer;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Contact extends Model
{
    use HasFactory, LogsActivity;

    protected $guarded = ['id', 'phone_e164'];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'privacy_notice_sent_at' => 'date',
            'phone_consent_at' => 'date',
            'phone_consent_last_used_at' => 'date',
            'health_consent_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Contact $contact) {
            $contact->email = Normalizer::email($contact->email);

            if ($contact->isDirty('phone_display')) {
                $contact->phone_display = Phone::display($contact->phone_display);
                $contact->phone_e164 = Phone::normalize($contact->phone_display);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('contacts')
            ->logAll()
            ->logExcept(['created_at', 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([$this->salutation, $this->first_name, $this->last_name])));
    }

    public function hasPhoneConsent(): bool
    {
        return $this->phone_consent_at !== null && filled($this->phone_consent_proof);
    }

    public function isBlocked(): bool
    {
        return BlocklistEntry::matches(phone: $this->phone_e164, email: $this->email);
    }
}
