<?php

namespace App\Support;

/**
 * Ähnlichkeit von Namen für die Dublettenprüfung, Werte von 0 (verschieden) bis 1 (gleich).
 */
class Similarity
{
    /** Jaro-Winkler: robust gegen Tippfehler und vertauschte Buchstaben, betont gleiche Anfänge. */
    public static function jaroWinkler(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $lengthA = mb_strlen($a);
        $lengthB = mb_strlen($b);

        if ($lengthA === 0 || $lengthB === 0) {
            return 0.0;
        }

        $charsA = mb_str_split($a);
        $charsB = mb_str_split($b);
        $window = max(0, intdiv(max($lengthA, $lengthB), 2) - 1);
        $matchedA = array_fill(0, $lengthA, false);
        $matchedB = array_fill(0, $lengthB, false);
        $matches = 0;

        for ($i = 0; $i < $lengthA; $i++) {
            $from = max(0, $i - $window);
            $to = min($i + $window + 1, $lengthB);

            for ($j = $from; $j < $to; $j++) {
                if (! $matchedB[$j] && $charsA[$i] === $charsB[$j]) {
                    $matchedA[$i] = $matchedB[$j] = true;
                    $matches++;
                    break;
                }
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        $transpositions = 0;
        $k = 0;

        for ($i = 0; $i < $lengthA; $i++) {
            if (! $matchedA[$i]) {
                continue;
            }

            while (! $matchedB[$k]) {
                $k++;
            }

            if ($charsA[$i] !== $charsB[$k]) {
                $transpositions++;
            }

            $k++;
        }

        $jaro = ($matches / $lengthA + $matches / $lengthB + ($matches - $transpositions / 2) / $matches) / 3;

        $prefix = 0;

        for ($i = 0; $i < min(4, $lengthA, $lengthB); $i++) {
            if ($charsA[$i] !== $charsB[$i]) {
                break;
            }

            $prefix++;
        }

        return $jaro + $prefix * 0.1 * (1 - $jaro);
    }

    /** Anteil gemeinsamer Wörter (Dice), unabhängig von der Reihenfolge („Bau Müller“ = „Müller Bau“). */
    public static function tokens(array $a, array $b): float
    {
        $a = array_values(array_unique($a));
        $b = array_values(array_unique($b));

        if ($a === [] || $b === []) {
            return 0.0;
        }

        return 2 * count(array_intersect($a, $b)) / (count($a) + count($b));
    }

    /** Eigentlicher Name ohne Rechtsform und allgemeine Zusätze, z. B. „Brückner & Partner GmbH“ → „brueckner“. */
    public static function companyCore(?string $name): ?string
    {
        return implode('', Normalizer::companyTokens($name)) ?: Normalizer::companyName($name);
    }

    /** Steckt der kürzere Namenskern (mindestens 8 Buchstaben) ganz im längeren? */
    public static function companyContains(?string $a, ?string $b): bool
    {
        $coreA = (string) self::companyCore($a);
        $coreB = (string) self::companyCore($b);
        $shorter = mb_strlen($coreA) <= mb_strlen($coreB) ? $coreA : $coreB;
        $longer = $shorter === $coreA ? $coreB : $coreA;

        return mb_strlen($shorter) >= 8 && $shorter !== $longer && str_contains($longer, $shorter);
    }

    /**
     * Gesamtwert für Firmennamen: das Beste aus Zeichen- und Wortvergleich.
     * Steckt ein Name ganz im anderen („Müller Bau“ in „Müller Bau und Sanierung“), zählt das als sehr ähnlich.
     */
    public static function companyNames(?string $a, ?string $b): float
    {
        $compactA = Normalizer::companyName($a);
        $compactB = Normalizer::companyName($b);

        if ($compactA === null || $compactB === null) {
            return 0.0;
        }

        if ($compactA === $compactB) {
            return 1.0;
        }

        // Verglichen wird der eigentliche Name ohne allgemeine Zusätze: „Brückner & Partner“ gegen „Brunner & Partner“
        // ist „brueckner“ gegen „brunner“ und damit deutlich verschieden.
        $tokensA = Normalizer::companyTokens($a);
        $tokensB = Normalizer::companyTokens($b);
        $coreA = implode('', $tokensA) ?: $compactA;
        $coreB = implode('', $tokensB) ?: $compactB;

        if ($coreA === $coreB) {
            return 0.97;
        }

        $shorter = mb_strlen($coreA) <= mb_strlen($coreB) ? $coreA : $coreB;
        $longer = $shorter === $coreA ? $coreB : $coreA;
        $contained = mb_strlen($shorter) >= 6 && str_contains($longer, $shorter) ? 0.9 : 0.0;

        return max(
            self::jaroWinkler($coreA, $coreB),
            self::tokens($tokensA, $tokensB),
            $contained,
        );
    }
}
