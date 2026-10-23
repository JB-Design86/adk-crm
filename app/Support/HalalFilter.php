<?php

namespace App\Support;

/**
 * Halal-Filter für den Import (config adk.halal), für Marken mit halal_filter:
 * Firmenname und Branche gegen Muster, WZ-Code gegen Codeanfänge.
 */
class HalalFilter
{
    /**
     * Treffer oder null. type „exclude“ = nie übernehmen, „review“ = erst nach Prüfung.
     *
     * @return array{type: string, category: string, match: string}|null
     */
    public static function check(?string $name, ?string $industry, ?string $wzCode): ?array
    {
        $config = config('adk.halal');
        $text = trim(($name ?? '').' | '.($industry ?? ''));
        $wz = preg_replace('/\D/', '', (string) $wzCode);

        foreach (['exclude', 'review'] as $type) {
            foreach ($config[$type] as $category => $pattern) {
                if (preg_match('/'.$pattern.'/iu', $text, $match)) {
                    return ['type' => $type, 'category' => $category, 'match' => $match[0]];
                }
            }

            foreach ($config[$type.'_wz'] as $code => $label) {
                if ($wz !== '' && str_starts_with($wz, (string) $code)) {
                    return ['type' => $type, 'category' => $label, 'match' => 'WZ '.$wzCode];
                }
            }
        }

        return null;
    }

    /** Begründung für das Importprotokoll, z. B. „Halal-Filter: Alkohol („Brauerei“)“. */
    public static function reason(array $hit): string
    {
        return ($hit['type'] === 'exclude' ? 'Halal-Filter: ' : 'Halal-Prüffall: ').$hit['category'].' („'.$hit['match'].'“)'
            .($hit['type'] === 'review' ? '. Nach Prüfung mit „Halal-Prüffälle übernehmen“ erneut importieren.' : '');
    }
}
