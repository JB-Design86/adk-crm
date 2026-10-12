<?php

namespace App\Filament\Forms;

use App\Services\Microsoft\GraphMailer;
use App\Support\MailHtml;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;

/**
 * HTML-Code statt Editor für E-Mail-Text, in Vorlagen und in „E-Mail schreiben“: Schalter, Feld für den Code
 * und Vorschau. Gestaltete Mails (Tabellen, Farben) überstehen den Editor nicht, deshalb laufen sie als Code
 * und werden beim Speichern bzw. Senden mit MailHtml::sanitize($html, code: true) bereinigt.
 */
class MailCode
{
    /** Schalter; beim Umschalten wandert der Text mit (in den Editor nur, was er darstellen kann). */
    public static function toggle(string $name, string $editor, string $code): Toggle
    {
        return Toggle::make($name)
            ->label('Als HTML-Code bearbeiten')
            ->helperText('Für gestaltete E-Mails, z. B. mit Tabellen und Farben. Ein ganzes HTML-Dokument mit <!DOCTYPE> geht auch. Wirksam sind Inline-Stile (style="…"), Tabellen und Links. <head>, <style>, Skripte und Bilder entfernt das CRM. Zurück in den Editor gehen Tabellen und Farben verloren.')
            ->live()
            ->afterStateUpdated(function (Get $get, Set $set, ?bool $state) use ($editor, $code) {
                if ($state) {
                    $set($code, self::codeFromEditor($get($editor)));
                } else {
                    $set($editor, MailHtml::normalize($get($code)));
                }
            });
    }

    public static function field(string $name, string $toggle, ?string $helperText = null): Textarea
    {
        return Textarea::make($name)
            ->label('Text als HTML-Code')
            ->rows(16)
            ->required()
            ->live(onBlur: true)
            ->extraInputAttributes(['class' => 'font-mono text-sm', 'spellcheck' => 'false'])
            ->visible(fn (Get $get) => (bool) $get($toggle))
            ->helperText($helperText);
    }

    /** Vorschau ohne Signatur, so bereinigt, wie die Mail herausgeht, abgeschottet im iframe (ohne Skripte). */
    public static function preview(string $code, string $toggle): View
    {
        return View::make('filament.forms.mail-preview')
            ->viewData(fn (Get $get) => ['html' => self::previewHtml($get($code))])
            ->visible(fn (Get $get) => (bool) $get($toggle));
    }

    public static function previewHtml(?string $code): ?string
    {
        $html = MailHtml::sanitize(MailHtml::normalize($code), code: true);

        return MailHtml::toText($html) === '' ? null : GraphMailer::html($html);
    }

    /**
     * HTML aus dem Editor als Code. Wurde HTML-Code in den Editor eingefügt, steht er dort als Text:
     * Dann gilt dieser Text als Code, nicht das HTML des Editors.
     */
    public static function codeFromEditor(mixed $state): string
    {
        $html = is_array($state) ? RichContentRenderer::make($state)->toUnsafeHtml() : (string) $state;
        $text = MailHtml::toText($html);

        return MailHtml::isHtml($text) ? $text : $html;
    }
}
