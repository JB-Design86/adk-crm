<?php

/*
|--------------------------------------------------------------------------
| ADK CRM – fachliche Konfiguration
|--------------------------------------------------------------------------
|
| Status, Zielgruppen, Eingangskanäle, Fristen und Tastenbelegung stehen
| hier im Code, damit jede Änderung über Git nachvollziehbar bleibt.
| Die Schlüssel (links) werden in der Datenbank gespeichert und dürfen
| nachträglich nicht umbenannt werden. Die Bezeichnungen (label) schon.
|
*/

return [

    /*
    | Rollen. Rechte je Rolle; '*' bedeutet alle Rechte.
    | Eine weitere Rolle wird hier ergänzt, ohne Datenbankänderung.
    */
    'roles' => [
        'admin' => [
            'label' => 'Verwaltung',
            'permissions' => ['*'],
        ],
        'staff' => [
            'label' => 'Mitarbeitende',
            'permissions' => [
                'leads.view',
                'leads.edit',
                'call_list',
                'appointments.own',
                'organizations.edit',
                'contacts.edit',
                'reports.view',
            ],
        ],
    ],

    /*
    | Alle Rechte, die im System geprüft werden (für '*' und Dokumentation).
    */
    'permissions' => [
        'leads.view' => 'Vorgänge ansehen',
        'leads.edit' => 'Vorgänge bearbeiten',
        'call_list' => 'Anrufliste nutzen',
        'appointments.own' => 'eigene Termine bearbeiten',
        'appointments.all' => 'alle Termine bearbeiten',
        'organizations.edit' => 'Organisationen bearbeiten',
        'contacts.edit' => 'Kontakte bearbeiten',
        'reports.view' => 'Auswertung ansehen',
        'import' => 'Importieren',
        'export' => 'Exportieren',
        'users.manage' => 'Benutzer verwalten',
        'blocklist.manage' => 'Sperrliste bearbeiten',
        'audit.view' => 'Protokoll einsehen',
        'health.view' => 'Gesundheitsangaben einsehen',
    ],

    /*
    | Zielgruppen nach den Aufnahmewegen der ADK.
    */
    'target_groups' => [
        'A' => ['label' => 'A · Grundsicherung (Jobcenter)'],
        'B' => ['label' => 'B · Arbeitsuchend (Agentur für Arbeit)'],
        'C' => ['label' => 'C · Beschäftigte eines Betriebs (§ 82 SGB III)'],
        'D' => ['label' => 'D · Geschäftsführung eines Betriebs'],
        'E' => ['label' => 'E · Arbeitsunfall (BG, Rentenversicherung)'],
        'self_payer' => ['label' => 'Selbstzahler'],
        'company_open' => ['label' => 'Betrieb, Zuordnung offen'],
    ],

    /*
    | Eingangskanäle. inbound = eingehende Anfrage (rot und ganz oben,
    | Wiedervorlage heute, Anruf bei Privatpersonen erlaubt).
    */
    'channels' => [
        'cold_call' => ['label' => 'Kaltakquise (Leadliste)', 'inbound' => false],
        'website_form' => ['label' => 'Website-Formular', 'inbound' => true],
        'email_info' => ['label' => 'E-Mail an info@', 'inbound' => true],
        'phone' => ['label' => 'Anruf', 'inbound' => true],
        'whatsapp' => ['label' => 'WhatsApp', 'inbound' => true],
        'calendly' => ['label' => 'Calendly', 'inbound' => true],
        'kursnet' => ['label' => 'KURSNET', 'inbound' => true],
        'referral_agent' => ['label' => 'Empfehlung Vermittlungsfachkraft', 'inbound' => true],
        'network' => ['label' => 'persönliches Netzwerk', 'inbound' => true],
        'google_ads' => ['label' => 'Google Ads', 'inbound' => true],
        'social_media' => ['label' => 'LinkedIn und Social Media', 'inbound' => true],
        'chatgpt_ad' => ['label' => 'ChatGPT-Anzeige', 'inbound' => true],
        'other' => ['label' => 'Sonstiges', 'inbound' => true],
    ],

    /*
    | Status. key = Taste in der Anrufliste (null = keine Taste).
    | follow_up: 'today' | 'working_days' (mit days) | 'required' | 'appointment' | null
    | closes: Vorgang wird geschlossen.
    | reached: zählt in der Auswertung als „erreicht“.
    */
    'statuses' => [
        'new' => [
            'label' => 'Neu', 'key' => null, 'color' => 'danger',
            'follow_up' => 'today', 'closes' => false, 'reached' => false,
        ],
        'not_reached' => [
            'label' => 'Nicht erreicht', 'key' => '1', 'color' => 'gray',
            'follow_up' => 'working_days', 'days' => 2, 'closes' => false, 'reached' => false,
        ],
        'interested' => [
            'label' => 'Interesse vorhanden, nachfassen', 'key' => '2', 'color' => 'success',
            'follow_up' => 'required', 'closes' => false, 'reached' => true,
        ],
        'documents_sent' => [
            'label' => 'Unterlagen versendet', 'key' => '3', 'color' => 'info',
            'follow_up' => 'working_days', 'days' => 5, 'closes' => false, 'reached' => true,
        ],
        'appointment' => [
            'label' => 'Termin vereinbart', 'key' => '4', 'color' => 'primary',
            'follow_up' => 'appointment', 'closes' => false, 'reached' => true,
        ],
        'later' => [
            'label' => 'Später Interesse', 'key' => '5', 'color' => 'warning',
            'follow_up' => 'required', 'closes' => false, 'reached' => true,
        ],
        'no_interest' => [
            'label' => 'Kein Interesse', 'key' => '6', 'color' => 'gray',
            'follow_up' => null, 'closes' => true, 'reached' => true,
        ],
        'no_need' => [
            'label' => 'Kein Bedarf', 'key' => '7', 'color' => 'gray',
            'follow_up' => null, 'closes' => true, 'reached' => true,
        ],
        'objection' => [
            'label' => 'Werbewiderspruch', 'key' => '8', 'color' => 'danger',
            'follow_up' => null, 'closes' => true, 'reached' => true, 'blocklist' => true,
        ],
        'wrong_data' => [
            'label' => 'Datensatz falsch', 'key' => '9', 'color' => 'gray',
            'follow_up' => null, 'closes' => true, 'reached' => false, 'requires_reason' => true,
        ],
        'handed_over' => [
            'label' => 'Übergeben an Förderweg', 'key' => null, 'color' => 'success',
            'follow_up' => null, 'closes' => false, 'reached' => true,
        ],
    ],

    /*
    | Taste für das Merkmal Cross-Selling JB Design (ändert den Status nicht).
    */
    'cross_selling_key' => '0',

    /*
    | Nach so vielen erfolglosen Versuchen fragt die Anrufliste
    | „Vorgang ruhen lassen?“. Ruhen = Wiedervorlage um rest_months verschieben.
    */
    'not_reached_rest_after' => 3,
    'not_reached_rest_months' => 3,

    /*
    | Gründe für Status „Datensatz falsch“.
    */
    'wrong_data_reasons' => [
        'company_gone' => 'Firma besteht nicht',
        'wrong_number' => 'Nummer falsch',
        'other' => 'Sonstiges',
    ],

    /*
    | Terminarten.
    */
    'appointment_types' => [
        'phone' => 'Telefon',
        'teams' => 'Teams',
        'onsite' => 'vor Ort',
    ],

    /*
    | Prioritäten der Leadliste in Reihenfolge der Anrufliste.
    */
    'priorities' => ['A', 'B', 'C'],

    /*
    | Branchen (Leadliste). Freie Eingabe bleibt möglich, diese Liste
    | dient als Auswahl und für die Testdaten.
    */
    'industries' => [
        'Steuerberatung' => '69.20',
        'Rechtsberatung' => '69.10',
        'Architektur- und Ingenieurbüros' => '71.1',
        'Immobilienmakler und -verwaltung' => '68.3',
        'Versicherungsvermittlung' => '66.22',
        'Unternehmensberatung' => '70.22',
        'Werbung und Marketing' => '73.1',
        'Elektroinstallation' => '43.21',
        'Großhandel' => '46',
        'Spedition und Logistik' => '52.29',
    ],

    /*
    | Prüfstufen der Leadliste (Branchenmatrix).
    */
    'check_levels' => [
        1 => 'Prüfstufe 1',
        2 => 'Prüfstufe 2',
        3 => 'Prüfstufe 3',
        4 => 'Prüfstufe 4',
        5 => 'Prüfstufe 5',
    ],

    /*
    | Arbeitstage: Montag bis Freitag, bundeseinheitliche Feiertage plus
    | die Feiertage des Bundeslands. Unterstützt: 'RP' (Rheinland-Pfalz).
    */
    'holiday_region' => 'RP',

    /*
    | Löschfristen (Löschkonzept A-22). Täglicher Löschlauf: adk:loeschlauf.
    */
    'retention' => [
        // Vorgang ohne Vertragsschluss, Betrieb: Monate ab letztem Kontakt
        'lead_company_months' => 24,
        // Vorgang ohne Vertragsschluss, Privatperson: Monate ab letztem Kontakt
        'lead_private_months' => 6,
        // Nachweis Einwilligung Telefonansprache: Jahre ab Erteilung bzw. letzter Verwendung
        'phone_consent_years' => 5,
        // Importprotokoll: Jahre ab Ende des Kalenderjahres
        'import_log_years' => 3,
        // Uhrzeit des täglichen Löschlaufs
        'run_at' => '02:30',
    ],

    /*
    | Standardland für die Normalisierung von Telefonnummern.
    */
    'phone_default_country_code' => '49',

];
