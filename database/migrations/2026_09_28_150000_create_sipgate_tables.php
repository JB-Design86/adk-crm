<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sipgate: Verbindung je Benutzer (Tokens verschlüsselt) und Kennung/Dauer
 * an Aktivitäten, die aus der sipgate-Anrufliste stammen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sipgate_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('sipgate_user_id', 50);
            $table->string('device_id', 50)->nullable();
            $table->string('device_alias')->nullable();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at');
            $table->timestamp('refresh_expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->string('external_id', 100)->nullable()->unique();
            $table->unsignedInteger('duration_seconds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn(['external_id', 'duration_seconds']);
        });

        Schema::dropIfExists('sipgate_connections');
    }
};
