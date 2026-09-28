<?php

namespace App\Support;

use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Support\Icons\Heroicon;

/**
 * Hilfetexte aus lang/de/hilfe.php: Fragezeichen an Feldern, Satz je Seite, Status-Erklärungen.
 */
class Hilfe
{
    /** Hilfetext zu einem Feldnamen; bei „organization.priority“ zählt „priority“. */
    public static function feld(?string $name): ?string
    {
        if (blank($name)) {
            return null;
        }

        $texts = __('hilfe.felder');

        if (! is_array($texts)) {
            return null;
        }

        return $texts[$name] ?? $texts[last(explode('.', $name))] ?? null;
    }

    public static function seite(string $key): ?string
    {
        $text = __("hilfe.seiten.{$key}");

        return $text === "hilfe.seiten.{$key}" ? null : $text;
    }

    public static function status(?string $key): ?string
    {
        if (blank($key)) {
            return null;
        }

        $text = __("hilfe.status.{$key}");

        return $text === "hilfe.status.{$key}" ? null : $text;
    }

    /**
     * Hängt an alle Formularfelder und Angaben mit bekanntem Namen ein ? mit Tooltip.
     * Wird einmal im AppServiceProvider registriert.
     */
    public static function register(): void
    {
        Field::configureUsing(function (Field $field) {
            if ($text = self::feld($field->getName())) {
                $field->hintIcon(Heroicon::OutlinedQuestionMarkCircle, tooltip: $text);
            }
        });

        Entry::configureUsing(function (Entry $entry) {
            if ($text = self::feld($entry->getName())) {
                $entry->hintIcon(Heroicon::OutlinedQuestionMarkCircle, tooltip: $text);
            }
        });
    }
}
