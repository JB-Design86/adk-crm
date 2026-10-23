<?php

namespace App\Services;

use App\Models\Lead;
use App\Support\Brand;

/**
 * Vorschlag, womit die Anruferin ins Gespräch geht (config adk.offer): ADK, JB IT-Design oder das Paket.
 * Nur ein Hinweis aus den Stammdaten, entschieden wird im Gespräch („Vorgang für … anlegen“).
 */
final class OfferSuggestion
{
    private function __construct(
        public readonly string $offer,
        public readonly string $reason,
    ) {}

    public static function for(Lead $lead): ?self
    {
        $organization = $lead->organization;

        if ($organization === null) {
            return null;
        }

        $employees = $organization->employee_count;
        $outdated = WebsiteCheck::isOutdated($organization);
        $hasWebsite = filled($organization->website);
        $packageFrom = (int) config('adk.offer.package_min_employees');

        if ($employees !== null && $employees <= 1) {
            return new self('jb', 'Keine Angestellten, also keine Förderung über die ADK.'.($outdated ? ' Website veraltet.' : ''));
        }

        if ($employees !== null && $employees >= $packageFrom && ($hasWebsite || $outdated)) {
            return new self('package', "{$employees} Mitarbeitende und eine eigene Website".($outdated ? ' (veraltet)' : '').': Kurs bei der ADK, Umsetzung durch JB IT-Design.');
        }

        if ($employees !== null && $employees >= 2) {
            return new self('adk', "{$employees} Mitarbeitende: Weiterbildung mit Förderung.");
        }

        if ($outdated) {
            return new self('jb', 'Website veraltet. Erste Frage: Wie viele Mitarbeitende? Ab '.$packageFrom.' passt das Paket.');
        }

        return null;
    }

    public function label(): string
    {
        return match ($this->offer) {
            'package' => 'Paket (ADK + JB IT-Design)',
            default => Brand::label($this->offer),
        };
    }

    /** z. B. „Paket (ADK + JB IT-Design): 12 Mitarbeitende und eine eigene Website …“ */
    public function text(): string
    {
        return $this->label().': '.$this->reason;
    }
}
