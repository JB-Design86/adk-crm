<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Ergebnis einer Prüfstufe für eine Organisation: bestanden (true),
 * nicht bestanden (false). Ohne Eintrag gilt die Stufe als offen.
 */
class OrganizationCheck extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('organization_checks')
            ->logOnly(['organization_id', 'check_level_id', 'passed'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(CheckLevel::class, 'check_level_id');
    }
}
