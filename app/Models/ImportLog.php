<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'retrieved_at' => 'date',
            'skipped_rows' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
