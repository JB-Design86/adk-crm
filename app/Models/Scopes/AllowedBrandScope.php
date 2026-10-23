<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Konten mit eingeschränkten Marken (z. B. Vertrieb nur für JB IT-Design) sehen nur Vorgänge
 * dieser Marken, überall im CRM. Ohne angemeldete Person (Befehle, Löschlauf) gilt keine Einschränkung.
 * Wer markenübergreifend prüfen muss (Ampel), fragt ausdrücklich ohne diesen Scope.
 */
class AllowedBrandScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if ($user instanceof User && $user->isBrandRestricted()) {
            $builder->whereIn($model->qualifyColumn('brand'), $user->allowedBrands());
        }
    }
}
