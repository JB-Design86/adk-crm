<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vorlagen als HTML-Code (z. B. gestaltete Mails mit Tabellen): Kennzeichen, damit der Text nicht durch den Editor läuft,
 * und mehr Platz für den Text (MEDIUMTEXT statt TEXT, gestaltete Mails sind schnell länger als 64 KB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->mediumText('body')->change();
            $table->boolean('body_is_code')->default(false)->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('body_is_code');
            $table->text('body')->change();
        });
    }
};
