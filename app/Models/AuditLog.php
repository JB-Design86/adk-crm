<?php

namespace App\Models;

use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * Protokoll (Tabelle activity_log). Einträge sind nicht änderbar.
 * Gelöscht wird nur durch den Löschlauf, zusammen mit dem Datensatz,
 * auf den sich der Eintrag bezieht.
 */
class AuditLog extends Activity
{
    public static bool $allowDeletion = false;

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Protokolleinträge sind nicht änderbar.');
        });

        static::deleting(function () {
            if (! static::$allowDeletion) {
                throw new LogicException('Protokolleinträge werden nur durch den Löschlauf entfernt.');
            }
        });
    }
}
