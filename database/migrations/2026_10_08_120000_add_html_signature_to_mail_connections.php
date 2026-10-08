<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signatur mit Formatierung (HTML aus dem Editor) und Logo als eingebettetes Bild.
 * Die bisherige Text-Signatur (signature) bleibt als Rückfall, bis eine HTML-Signatur gespeichert ist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_connections', function (Blueprint $table) {
            $table->text('signature_html')->nullable()->after('signature');
            $table->string('signature_logo_path')->nullable()->after('signature_html');
            $table->unsignedSmallInteger('signature_logo_width')->nullable()->after('signature_logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('mail_connections', function (Blueprint $table) {
            $table->dropColumn(['signature_html', 'signature_logo_path', 'signature_logo_width']);
        });
    }
};
