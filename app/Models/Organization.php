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

    public function checks(): HasMany
    {
        return $this->hasMany(OrganizationCheck::class);
    }

    /**
     * Ergebnisse je Prüfstufe für Formulare: [check_level_id => 'passed'|'failed'|'open'].
     *
     * @return array<int, string>
     */
    public function checkStates(): array
    {
        $results = $this->checks()->pluck('passed', 'check_level_id');
        $states = [];

        foreach (CheckLevel::query()->ordered()->pluck('id') as $levelId) {
            $states[$levelId] = match ($results[$levelId] ?? null) {
                true => 'passed',
                false => 'failed',
                default => 'open',
            };
        }

        return $states;
    }

    /**
     * Speichert Ergebnisse je Prüfstufe. Werte: true/'passed', false/'failed', null/'open'.
     * Stufen, die nicht übergeben werden, bleiben unverändert.
     *
     * @param  array<int|string, bool|string|null>  $states
     */
    public function syncChecks(array $states): void
    {
        $known = CheckLevel::query()->pluck('id')->all();

        foreach ($states as $levelId => $state) {
            $levelId = (int) $levelId;

            if (! in_array($levelId, $known, true)) {
                continue;
            }

            $passed = match ($state) {
                true, 'passed', '1', 1 => true,
                false, 'failed', '0', 0 => false,
                default => null,
            };

            $existing = $this->checks()->where('check_level_id', $levelId)->first();

            if ($passed === null) {
                $existing?->delete();

                continue;
            }

            if ($existing) {
                $existing->update(['passed' => $passed]);
            } else {
                $this->checks()->create(['check_level_id' => $levelId, 'passed' => $passed]);
            }
        }
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
