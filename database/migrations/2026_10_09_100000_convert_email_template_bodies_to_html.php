<?php

use App\Support\MailHtml;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E-Mail-Vorlagen mit Formatierung: Der Text kommt jetzt als HTML aus dem Editor. Bisherige Texte
 * ohne Tags werden zu Absätzen (Leerzeile = neuer Absatz, Zeilenumbruch = <br>, alles maskiert,
 * Adressen mit http(s) als Link). Ohne Protokolleintrag, der Inhalt bleibt derselbe.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('email_templates')->orderBy('id')->get(['id', 'body'])
            ->reject(fn (object $template) => MailHtml::isHtml($template->body))
            ->each(fn (object $template) => DB::table('email_templates')->where('id', $template->id)->update(['body' => MailHtml::fromText($template->body)]));
    }

    public function down(): void
    {
        // HTML bleibt. Der Versand behandelt Texte mit und ohne Tags gleich gut.
    }
};
