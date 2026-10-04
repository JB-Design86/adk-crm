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

    /** Rechtsformen, Füllwörter und allgemeine Zusätze („& Partner“, „Gruppe“) zählen beim Namensvergleich nicht. */
    private const STOP_WORDS = ['und', 'der', 'die', 'das', 'fuer', 'von', 'zu', 'am', 'im', 'in', 'co', 'gmbh', 'ug', 'ag', 'kg', 'ohg', 'gbr', 'ek', 'ev', 'mbh', 'haftungsbeschraenkt', 'inh', 'inhaber', 'partner', 'partners', 'partnerschaft', 'soehne', 'sohn', 'gruppe', 'group', 'holding', 'deutschland', 'germany', 'international'];

    /** @return list<string> Wörter des Firmennamens, klein, ohne Umlaute und Füllwörter */
    public static function companyTokens(?string $name): array
    {
        if ($name === null || trim($name) === '') {
            return [];
        }

        $words = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($name, 'de')), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($words, fn (string $word) => strlen($word) > 1 && ! in_array($word, self::STOP_WORDS, true)));
    }

    /** Straße als Vergleichsform: „Rheinstraße 12a“ = „Rheinstr. 12 a“ → „rheinstr12a“. */
    public static function street(?string $street): ?string
    {
        if ($street === null || trim($street) === '') {
            return null;
        }

        $street = Str::lower(Str::ascii(trim($street), 'de'));
        $street = preg_replace('/(strasse|str\.?)(?=\s|\d|$)/', 'str', $street);
        $street = preg_replace('/[^a-z0-9]+/', '', $street);

        return $street === '' ? null : $street;
    }

    /** Personenname als Vergleichsform, ohne Titel: „Dr. Jörg Müller“ → „joergmueller“. */
    public static function personName(?string $firstName, ?string $lastName): ?string
    {
        $name = Str::lower(Str::ascii(trim(($firstName ?? '').' '.($lastName ?? '')), 'de'));
        $name = preg_replace('/\b(dr|prof|dipl|ing|med)\b\.?/', ' ', $name);
        $name = preg_replace('/[^a-z]+/', '', $name);

        return $name === '' ? null : $name;
    }
}
