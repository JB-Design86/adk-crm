<?php

namespace App\Support;

use App\Models\User;

/**
 * Marken aus config/adk.php (brands) und die Marke, die oben im CRM gewählt ist.
 * Die Auswahl gilt für Listen (Heute, Anrufliste, Vorgänge, Kalender, Auswertung). Welche Marken
 * ein Konto überhaupt sieht, steht am Benutzer (User::allowedBrands()).
 */
class Brand
{
    public const SESSION_KEY = 'crm.brand';

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(config('adk.brands'));
    }

    public static function exists(?string $brand): bool
    {
        return $brand !== null && array_key_exists($brand, config('adk.brands'));
    }

    public static function default(): string
    {
        return config('adk.default_brand');
    }

    /** @return array<string, string> Schlüssel => Kurzname, auf Wunsch nur die Marken des Kontos */
    public static function options(?User $user = null): array
    {
        $keys = $user ? $user->allowedBrands() : self::keys();

        return collect($keys)->mapWithKeys(fn (string $brand) => [$brand => self::label($brand)])->all();
    }

    public static function label(?string $brand): ?string
    {
        return $brand === null ? null : (config("adk.brands.{$brand}.label") ?? $brand);
    }

    /** Voller Name, z. B. „JB IT-Design & Marketing“. */
    public static function name(?string $brand): ?string
    {
        return $brand === null ? null : (config("adk.brands.{$brand}.name") ?? self::label($brand));
    }

    public static function color(?string $brand): string
    {
        return config("adk.brands.{$brand}.color") ?? 'gray';
    }

    /** Die jeweils andere Marke (bei zwei Marken genau eine). */
    public static function other(string $brand): ?string
    {
        return collect(self::keys())->first(fn (string $key) => $key !== $brand);
    }

    public static function hasHalalFilter(?string $brand): bool
    {
        return (bool) config("adk.brands.{$brand}.halal_filter", false);
    }

    /**
     * Oben gewählte Marke, oder null für „alle Marken“. Ein Konto mit nur einer Marke hat immer diese.
     */
    public static function current(): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $allowed = $user->allowedBrands();

        if (count($allowed) === 1) {
            return $allowed[0];
        }

        $selected = session(self::SESSION_KEY);

        return in_array($selected, $allowed, true) ? $selected : null;
    }

    /** Marke für Neues (Vorgang anlegen, Import): die gewählte, sonst die erste des Kontos. */
    public static function currentOrDefault(): string
    {
        $user = auth()->user();

        return self::current()
            ?? ($user instanceof User && ! in_array(self::default(), $user->allowedBrands(), true) ? $user->allowedBrands()[0] : self::default());
    }

    public static function select(?string $brand): void
    {
        session([self::SESSION_KEY => self::exists($brand) ? $brand : null]);
    }
}
