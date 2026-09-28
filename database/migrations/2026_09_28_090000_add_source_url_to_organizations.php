<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fundstelle je Organisation (z. B. Impressum-Seite), ergänzend zu Quelle und Abrufdatum.
 * Wichtig bei recherchierten Leadlisten und für Auskünfte nach Art. 14/15 DSGVO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('source_url', 500)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('source_url');
        });
    }
};
