<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Vergleichsformen für die Dublettenerkennung und die Sperrliste.
 */
class Normalizer
{
    /** Firmenname ohne Rechtsform, Satzzeichen und Groß-/Kleinschreibung. */
    public static function companyName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $name = Str::lower(Str::ascii(trim($name), 'de'));
        $name = preg_replace('/(gmbh\s*&\s*co\.?\s*kg|\bgmbh\b|\bug\b|haftungsbeschraenkt|\bag\b|\bkg\b|\bohg\b|\bgbr\b|\be\.?\s?k\.?(?=\s|$)|\be\.?\s?v\.?(?=\s|$)|\bmbb\b|\bpartg\b|\bltd\b)/', ' ', $name);
        $name = preg_replace('/[^a-z0-9]+/', '', $name);

        return $name === '' ? null : $name;
    }

    /** Domain einer Website ohne Schema und „www.“. */
    public static function domain(?string $website): ?string
    {
        if ($website === null || trim($website) === '') {
            return null;
        }

        $website = Str::lower(trim($website));

        if (! preg_match('#^[a-z]+://#', $website)) {
            $website = 'http://'.$website;
        }

        $host = parse_url($website, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        return preg_replace('/^www\./', '', $host);
    }

    public static function email(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return Str::lower(trim($email));
    }

    public static function postalCode(?string $postalCode): ?string
    {
        if ($postalCode === null || trim($postalCode) === '') {
            return null;
        }

        return preg_replace('/\s+/', '', trim($postalCode));
    }
}
