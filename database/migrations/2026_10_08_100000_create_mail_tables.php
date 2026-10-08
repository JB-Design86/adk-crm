<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-Mail aus dem Vorgang: Verbindung je Benutzer mit dem eigenen Microsoft-365-Postfach
 * (Tokens verschlüsselt, Signatur) und E-Mail-Vorlagen mit zwei Startvorlagen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mailbox');
            $table->string('display_name')->nullable();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at');
            $table->text('signature')->nullable();
            $table->timestamps();
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subject');
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });

        // Startvorlagen, nur in eine leere Tabelle. Danach pflegt die Verwaltung sie unter Verwaltung → E-Mail-Vorlagen.
        if (DB::table('email_templates')->doesntExist()) {
            $now = now();

            DB::table('email_templates')->insert([
                [
                    'name' => 'Unterlagen nach Telefonat',
                    'subject' => 'Ihre Unterlagen zur Weiterbildung bei der ADK',
                    'body' => "{anrede},\n\n"
                        ."vielen Dank für das freundliche Gespräch. Wie besprochen sende ich Ihnen unsere Unterlagen zu den Weiterbildungen der ADK.\n\n"
                        ."Welche Förderung in Ihrem Fall in Frage kommt, klären wir gern gemeinsam im nächsten Schritt. Wenn Sie Fragen haben, antworten Sie einfach auf diese E-Mail oder rufen Sie mich an.\n\n"
                        ."Wie wir mit Ihren Daten umgehen, lesen Sie in unseren Hinweisen zum Datenschutz: https://adk-akademie.de/datenschutz.html\n\n"
                        ."Mit freundlichen Grüßen\n{absender}",
                    'sort_order' => 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'name' => 'Nachfassen',
                    'subject' => 'Ihre Weiterbildung bei der ADK: kurze Rückfrage',
                    'body' => "{anrede},\n\n"
                        ."vor einigen Tagen habe ich Ihnen Informationen zu den Weiterbildungen der ADK geschickt. Ich wollte kurz nachfragen, ob Sie Gelegenheit hatten, sich die Unterlagen anzusehen, und ob noch Fragen offen sind.\n\n"
                        ."Gern bespreche ich mit Ihnen auch, welche Fördermöglichkeiten für Sie in Frage kommen. Antworten Sie einfach auf diese E-Mail oder nennen Sie mir einen passenden Zeitpunkt für ein kurzes Telefonat.\n\n"
                        ."Mit freundlichen Grüßen\n{absender}",
                    'sort_order' => 2,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('mail_connections');
    }
};
