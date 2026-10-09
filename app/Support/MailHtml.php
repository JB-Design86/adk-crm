<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Str;

/**
 * Text von E-Mails aus dem CRM: HTML aus dem Editor für den Versand bereinigen, reinen Text
 * (alte Vorlagen, Aufrufe ohne Editor) in Absätze umwandeln und für den Verlauf lesbaren Text erzeugen.
 */
class MailHtml
{
    /** Abstand unter Absätzen und Listen, direkt am Element, damit Outlook und Gmail gleich aussehen. */
    public const BLOCK_STYLE = 'margin:0 0 12px';

    /** Links in E-Mails nur mit diesen Schemata. */
    public const LINK_SCHEMES = ['http', 'https', 'mailto'];

    /** Knöpfe im Editor für E-Mail-Text und Vorlagen: nur, was in Outlook und Gmail sicher gleich aussieht. */
    public const TOOLBAR = [['bold', 'italic', 'underline', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']];

    /** Enthält der Text HTML-Tags? Ohne Tags gilt er als reiner Text. */
    public static function isHtml(?string $text): bool
    {
        return preg_match('#</?[a-z][a-z0-9]*(\s[^<>]*)?/?>#i', (string) $text) === 1;
    }

    /** HTML bleibt, reiner Text wird zu Absätzen (fromText). */
    public static function normalize(?string $text): string
    {
        return self::isHtml($text) ? trim((string) $text) : self::fromText($text);
    }

    /** Reiner Text als HTML: Leerzeile beginnt einen neuen Absatz, Zeilenumbruch wird <br>, alles maskiert, Adressen mit http(s) als Link. */
    public static function fromText(?string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text));

        if ($text === '') {
            return '';
        }

        return collect(preg_split("/\n[ \t]*\n\s*/", $text))
            ->map(fn (string $paragraph) => '<p>'.collect(explode("\n", trim($paragraph)))->map(self::linkify(...))->join('<br>').'</p>')
            ->join('');
    }

    /**
     * HTML für den Versand: bereinigt (Str::sanitizeHtml), ohne Bilder, Links nur als schlichtes <a href>
     * mit http, https oder mailto, Abstände als Inline-Stil, leere Absätze als Leerzeile.
     */
    public static function sanitize(string $html): string
    {
        $html = Str::sanitizeHtml($html);

        // Eingefügte Bilder wären groß oder würden nachgeladen. Dateien gehören in die Anhänge.
        $html = preg_replace('#<img\b[^>]*>#i', '', $html);

        $html = preg_replace_callback('#<a\b([^>]*)>(.*?)</a>#is', function (array $match) {
            $href = preg_match('#\shref="([^"]*)"#i', $match[1], $found) ? $found[1] : '';
            $scheme = Str::lower(Str::before($href, ':'));

            return str_contains($href, ':') && in_array($scheme, self::LINK_SCHEMES, true) ? '<a href="'.$href.'">'.$match[2].'</a>' : $match[2];
        }, $html);

        // Der Editor schreibt Listenpunkte als <li><p>…</p>; ohne den Absatz stehen die Punkte ohne Lücke untereinander.
        $html = preg_replace('#<li\b([^>]*)>\s*<p\b[^>]*>((?:(?!</p>).)*)</p>#is', '<li$1>$2', $html);

        $html = preg_replace_callback('#<(p|ul|ol)\b([^>]*)>#i', fn (array $match) => '<'.Str::lower($match[1]).preg_replace('#\sstyle="[^"]*"#i', '', $match[2]).' style="'.self::BLOCK_STYLE.'">', $html);
        $html = preg_replace('#<p style="'.preg_quote(self::BLOCK_STYLE, '#').'">(?:\s|&nbsp;|\x{00A0}|<br\s*/?>)*</p>#iu', '<p style="margin:0">&nbsp;</p>', $html);

        return trim($html);
    }

    /** Lesbarer Text für den Verlauf: Absätze und Zeilenumbrüche bleiben, Links als „Linktext (Adresse)“, Listen mit • bzw. 1. */
    public static function toText(?string $html): string
    {
        if (blank($html)) {
            return '';
        }

        $document = new DOMDocument;
        // Umlaute als Zeichenreferenzen, so liest der HTML-Parser von libxml sie unabhängig von seiner Version richtig.
        $document->loadHTML('<body>'.mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8').'</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $body = $document->getElementsByTagName('body')->item(0);

        $text = str_replace("\u{00A0}", ' ', $body ? self::textOf($body) : '');
        $text = preg_replace("/[ \t]+\n/", "\n", $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    private static function linkify(string $line): string
    {
        $parts = preg_split('#(https?://[^\s<>"]*[^\s<>".,;:!?)\]])#i', $line, -1, PREG_SPLIT_DELIM_CAPTURE);

        return collect($parts)->map(fn (string $part, int $index) => $index % 2 ? '<a href="'.e($part).'">'.e($part).'</a>' : e($part))->join('');
    }

    private static function textOf(DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $part = preg_replace('/[ \t\r\n]+/', ' ', $child->data);
                $text .= $text === '' || str_ends_with($text, "\n") ? ltrim($part, ' ') : $part;

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $text .= match (Str::lower($child->tagName)) {
                'br' => "\n",
                'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'table', 'tr' => "\n\n".trim(self::textOf($child), " \n")."\n\n",
                'ul', 'ol' => "\n\n".self::listText($child)."\n\n",
                'a' => self::linkText($child),
                default => self::textOf($child),
            };
        }

        return $text;
    }

    private static function listText(DOMElement $list): string
    {
        $ordered = Str::lower($list->tagName) === 'ol';
        $number = $list->hasAttribute('start') ? (int) $list->getAttribute('start') : 1;
        $items = [];

        foreach ($list->childNodes as $item) {
            if (! $item instanceof DOMElement || Str::lower($item->tagName) !== 'li') {
                continue;
            }

            $marker = $ordered ? ($number++).'. ' : '• ';
            $content = preg_replace("/\n{2,}/", "\n", trim(self::textOf($item), " \n"));
            $items[] = $marker.str_replace("\n", "\n".str_repeat(' ', mb_strlen($marker)), $content);
        }

        return implode("\n", $items);
    }

    private static function linkText(DOMElement $link): string
    {
        $label = trim(self::textOf($link));
        $href = trim($link->getAttribute('href'));
        $target = Str::startsWith(Str::lower($href), 'mailto:') ? substr($href, 7) : $href;

        if ($target === '' || $label === $href || $label === $target) {
            return $label !== '' ? $label : $target;
        }

        return $label === '' ? $target : $label.' ('.$target.')';
    }
}
