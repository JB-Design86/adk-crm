<?php

namespace App\Filament\Duplicates;

use App\Services\Duplicates\DuplicateFinder;
use App\Services\Duplicates\DuplicateMatch;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Hinweis im Formular, noch vor dem Speichern: Es gibt schon ähnliche Einträge im CRM.
 * Speichern bleibt möglich; der neue Eintrag kommt dann in die Dublettenprüfung.
 */
class DuplicateHint
{
    /** @var array<string, Collection<int, DuplicateMatch>> */
    private static array $cache = [];

    public const ORGANIZATION_FIELDS = ['name', 'street', 'postal_code', 'city', 'phone_display', 'email', 'website'];

    public const CONTACT_FIELDS = ['first_name', 'last_name', 'phone_display', 'email', 'organization_id', 'is_private'];

    public static function organization(): Callout
    {
        return self::callout(fn (Get $get, ?Model $record) => self::matches('organization', self::values($get, self::ORGANIZATION_FIELDS), $record));
    }

    public static function contact(): Callout
    {
        return self::callout(fn (Get $get, ?Model $record) => self::matches('contact', self::values($get, self::CONTACT_FIELDS), $record));
    }

    /** @param \Closure(Get, ?Model): Collection<int, DuplicateMatch> $matches */
    private static function callout(\Closure $matches): Callout
    {
        return Callout::make('Ähnliche Einträge im CRM')
            ->warning()
            ->columnSpanFull()
            ->visible(fn (Get $get, ?Model $record) => $matches($get, $record)->isNotEmpty())
            ->description(fn (Get $get, ?Model $record) => $matches($get, $record)
                ->take(3)
                ->map(fn (DuplicateMatch $match) => DuplicateFinder::describe($match->record).' ('.$match->reasonText().')')
                ->implode(' | ')
                .'. Bitte prüfen, ob es diesen Eintrag schon gibt. Wenn Sie trotzdem speichern, kommt der neue Eintrag in die Dublettenprüfung.');
    }

    /** @return array<string, mixed> */
    private static function values(Get $get, array $fields): array
    {
        return collect($fields)->mapWithKeys(fn (string $field) => [$field => $get($field)])->all();
    }

    /** @return Collection<int, DuplicateMatch> */
    private static function matches(string $type, array $data, ?Model $record): Collection
    {
        $key = $type.md5(serialize([$data, $record?->getKey()]));

        return self::$cache[$key] ??= $type === 'organization'
            ? app(DuplicateFinder::class)->organizationMatches($data, $record?->getKey())
            : app(DuplicateFinder::class)->contactMatches($data, $record?->getKey());
    }
}
