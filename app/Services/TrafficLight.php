<?php

namespace App\Services;

use App\Models\Lead;
use Carbon\CarbonImmutable;

/**
 * Ampel zwischen den Marken für einen Vorgang (Regeln in config adk.cross_brand, berechnet von CrossBrand).
 * Nur grün darf kalt angerufen werden.
 */
final class TrafficLight
{
    public const COLORS = [
        'red' => ['label' => 'gesperrt', 'color' => 'danger', 'icon' => 'heroicon-o-no-symbol'],
        'blue' => ['label' => 'persönlich', 'color' => 'info', 'icon' => 'heroicon-o-user'],
        'yellow' => ['label' => 'später', 'color' => 'warning', 'icon' => 'heroicon-o-clock'],
        'green' => ['label' => 'frei', 'color' => 'success', 'icon' => 'heroicon-o-check-circle'],
    ];

    public function __construct(
        public readonly string $color,
        public readonly ?string $reason = null,
        public readonly ?CarbonImmutable $until = null,
        public readonly ?Lead $other = null,
    ) {}

    public static function green(): self
    {
        return new self('green');
    }

    public function isGreen(): bool
    {
        return $this->color === 'green';
    }

    /** z. B. „Ampel: später“ */
    public function label(): string
    {
        return 'Ampel: '.self::COLORS[$this->color]['label'];
    }

    public function filamentColor(): string
    {
        return self::COLORS[$this->color]['color'];
    }

    public function icon(): string
    {
        return self::COLORS[$this->color]['icon'];
    }
}
