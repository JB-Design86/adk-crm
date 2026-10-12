<?php

/*
 * Nur die Grenzen für Anfragen an Livewire, alles andere bleibt bei den Vorgaben des Pakets.
 * Laravel ersetzt den Schlüssel „payload“ als Ganzes, deshalb stehen hier alle vier Werte.
 */
return [

    'payload' => [
        'max_size' => 1024 * 1024,
        // Der Editor (RichEditor) führt seinen Text als verschachteltes Dokument. Eine Tabelle mit Aufzählung
        // in der Zelle ergibt Pfade wie data.body.content.4.content.0.content.0.content.1.content.0.content.0.content.0,
        // im Fenster „E-Mail schreiben“ noch tiefer. Die Vorgabe von 10 Ebenen lehnte das mit „Fehler beim Laden der Seite“ ab.
        'max_nesting_depth' => 40,
        'max_calls' => 50,
        'max_components' => 200,
    ],

];
