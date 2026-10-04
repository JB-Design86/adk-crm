<?php

namespace App\Services\Duplicates;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Treffer der Dublettenprüfung: vorhandener Datensatz, Wert 0–100, sicher oder Verdacht, Gründe.
 */
final class DuplicateMatch
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly Model $record,
        public readonly int $score,
        public readonly bool $certain,
        public readonly array $reasons,
    ) {}

    public function reasonText(): string
    {
        return implode(', ', $this->reasons);
    }
}
