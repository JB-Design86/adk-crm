<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rückruf mit Uhrzeit.
 *
 * - leads.next_action_time: feste Uhrzeit zur Wiedervorlage (next_action_at), z. B. „morgen um 07:00 Uhr anrufen“.
 *   Leer = irgendwann am Tag. Der Index hält die Abfrage der Erinnerung klein, die jede Minute läuft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->time('next_action_time')->nullable()->after('next_action_at');
            $table->index('next_action_time', 'leads_next_action_time_index');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_next_action_time_index');
            $table->dropColumn('next_action_time');
        });
    }
};
