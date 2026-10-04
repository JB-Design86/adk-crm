<?php

use Illuminate\Support\Facades\DB;

it('hält alle Namen von Indizes und Fremdschlüsseln unter der MariaDB-Grenze von 64 Zeichen', function () {
    $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))->pluck('name');

    $tooLong = $tables->flatMap(fn (string $table) => collect(DB::select("PRAGMA index_list('{$table}')"))->pluck('name'))
        ->reject(fn (string $name) => str_starts_with($name, 'sqlite_autoindex'))
        ->merge($tables)
        ->filter(fn (string $name) => strlen($name) > 64)
        ->values()
        ->all();

    // Fremdschlüssel heißen in Laravel {tabelle}_{spalte}_foreign.
    $foreign = $tables->flatMap(fn (string $table) => collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
        ->map(fn ($fk) => "{$table}_{$fk->from}_foreign"))
        ->filter(fn (string $name) => strlen($name) > 64)
        ->values()
        ->all();

    expect($tooLong)->toBe([])->and($foreign)->toBe([]);
});
