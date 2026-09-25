<?php

namespace App\Support;

/**
 * Normalisierung von Telefonnummern nach E.164 (z. B. +4961311234567).
 */
class Phone
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        $input = trim($input);
        $hasPlus = str_starts_with($input, '+');
        $digits = preg_replace('/\D+/', '', $input);

        if ($digits === '' || strlen($digits) < 5) {
            return null;
        }

        $country = config('adk.phone_default_country_code', '49');

        if ($hasPlus) {
            $e164 = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $e164 = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $e164 = $country.substr($digits, 1);
        } else {
            $e164 = $country.$digits;
        }

        // Schreibweise „+49 (0) 6131 …“: die 0 nach der Ländervorwahl entfällt.
        if (str_starts_with($e164, $country.'0')) {
            $e164 = $country.substr($e164, strlen($country) + 1);
        }

        if (strlen($e164) > 15) {
            return null;
        }

        return '+'.$e164;
    }

    /** Anzeigeform: die eingegebene Schreibweise, bereinigt. */
    public static function display(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        return trim(preg_replace('/\s+/', ' ', $input));
    }
}
