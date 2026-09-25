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

class Organization extends Model
{
    use HasFactory, LogsActivity;

    protected $guarded = ['id', 'name_normalized', 'website_domain', 'phone_e164'];

    protected function casts(): array
    {
        return [
            'retrieved_at' => 'date',
            'is_training_company' => 'boolean',
            'employee_count' => 'integer',
            'check_1_passed' => 'boolean',
            'check_2_passed' => 'boolean',
            'check_3_passed' => 'boolean',
            'check_4_passed' => 'boolean',
            'check_5_passed' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Organization $organization) {
            $organization->name_normalized = Normalizer::companyName($organization->name) ?? '';
            $organization->website_domain = Normalizer::domain($organization->website);
            $organization->postal_code = Normalizer::postalCode($organization->postal_code);
            $organization->email = Normalizer::email($organization->email);

            if ($organization->isDirty('phone_display')) {
                $organization->phone_display = Phone::display($organization->phone_display);
                $organization->phone_e164 = Phone::normalize($organization->phone_display);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('organizations')
            ->logAll()
            ->logExcept(['name_normalized', 'website_domain', 'created_at', 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function importLog(): BelongsTo
    {
        return $this->belongsTo(ImportLog::class);
    }

    public function isBlocked(): bool
    {
        return BlocklistEntry::matches(
            phone: $this->phone_e164,
            email: $this->email,
            companyName: $this->name,
            postalCode: $this->postal_code,
        );
    }
}
