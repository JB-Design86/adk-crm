<?php

use Illuminate\Support\Facades\Schedule;

// Täglicher Löschlauf nach den Fristen in config/adk.php, Ausgabe in storage/logs/loeschlauf.log.
// Voraussetzung: geplante Aufgabe in Plesk „php artisan schedule:run“ jede Minute.
Schedule::command('adk:loeschlauf')
    ->dailyAt(config('adk.retention.run_at'))
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/loeschlauf.log'));
