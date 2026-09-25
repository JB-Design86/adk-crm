# ADK CRM – Hinweise für die Entwicklung

- Lastenheft: `docs/ADK_CRM_Lastenheft_v0.2.md`. Auftrag Stufe 1: `docs/AUFTRAG_STUFE1.md`. Stand der Abnahme: `docs/STUFE1_ABNAHME.md`. Betrieb: `docs/BETRIEB.md`.
- Laravel 13, Filament 5, Pest, spatie/laravel-activitylog, openspout. Wenige Zusatzpakete, jedes muss gepflegt sein.
- Nur erfundene Testdaten (Faker `de_DE`). Kein Zugriff auf Live-Server oder Live-Datenbank.
- Keine externen Dienste im Browser (keine CDNs, keine fremden Fonts oder Avatare, kein Tracking). Die CSP in `app/Http/Middleware/SecurityHeaders.php` erlaubt nur `'self'`.
- Oberfläche auf Deutsch mit Anrede „Sie“. Code, Tabellen und Variablen auf Englisch.
- Fachliche Konfiguration (Status, Zielgruppen, Kanäle, Fristen, Tasten, Rollen) steht in `config/adk.php`. Statusregeln nur über `App\Services\LeadStatusService`.
- Frontend-Assets werden lokal gebaut (`npm run build`) und committet (`public/build`). Auf dem Server läuft kein Node.
- Abweichungen vom Auftrag im Commit begründen und in `docs/STUFE1_ABNAHME.md` nachtragen.
- Tests: `php vendor/bin/pest`. Testdaten lokal: `php artisan migrate:fresh --seed`.
