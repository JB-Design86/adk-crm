# ADK CRM – Hinweise für die Entwicklung

- Lastenheft: `docs/ADK_CRM_Lastenheft_v0.2.md`. Auftrag Stufe 1: `docs/AUFTRAG_STUFE1.md`.
- Laravel 13, Filament 5, Pest, spatie/laravel-activitylog, openspout.
- Nur erfundene Testdaten (Faker `de_DE`). Kein Zugriff auf Live-Server oder Live-Datenbank.
- Keine externen Dienste im Browser (keine CDNs, keine fremden Fonts, kein Tracking).
- Oberfläche auf Deutsch mit Anrede „Sie“. Code, Tabellen und Variablen auf Englisch.
- Fachliche Konfiguration (Status, Zielgruppen, Kanäle, Fristen, Tasten) steht in `config/adk.php`.
- Frontend-Assets werden lokal gebaut und committet (`public/build`, `public/css`, `public/js`). Auf dem Server läuft kein Node.
- Tests: `php vendor/bin/pest`.
