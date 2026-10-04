# ADK CRM · Stufe 1 · Abnahmeübersicht

Stand 25.09.2026 · Grundlage: [Auftrag Stufe 1](AUFTRAG_STUFE1.md) und [Lastenheft v0.2](ADK_CRM_Lastenheft_v0.2.md) · Betrieb: [BETRIEB.md](BETRIEB.md)

Abnahme auf `crm-test.adk-akademie.de` mit den Testdaten (`php artisan migrate:fresh --seed`) und den Testkonten aus [BETRIEB.md](BETRIEB.md#61-nur-testumgebung-testdaten). Automatisierte Prüfung lokal: `php vendor/bin/pest` (116 Tests).

---

## 1 Technik

| Punkt | Umsetzung |
|---|---|
| Framework | Laravel 13.33, Filament 5.8 |
| PHP | 8.3 oder 8.4 (entwickelt und getestet mit 8.4) |
| Datenbank | MariaDB im Betrieb, SQLite für Tests und lokale Entwicklung |
| Tests | Pest 5 |
| Zusatzpakete | nur drei: `spatie/laravel-activitylog` (Protokoll), `openspout/openspout` (CSV/XLSX), `filament/filament`. Die Zwei-Faktor-Anmeldung ist in Filament enthalten, die Feiertage berechnet das CRM selbst |
| Assets | lokal gebaut, im Repository (`public/build`, `public/css`, `public/js`, `public/fonts`). Kein CDN, Schrift Inter lokal, Avatare als lokale SVG. Die Content-Security-Policy erlaubt nur den eigenen Server |
| Fachliche Konfiguration | `config/adk.php`: Rollen und Rechte, Zielgruppen, Kanäle (mit Kennzeichen „eingehend“), Status mit Taste, Wiedervorlageregel und „erreicht“, Gründe „Datensatz falsch“, Terminarten, Branchen, Feiertagsregion, Löschfristen |

---

## 2 Prüfliste gegen Lastenheft Abschnitt 9 (Zusagen der Datenschutzunterlagen)

| Zusage | Wo | So prüfen | Test |
|---|---|---|---|
| Eigenes Konto je Person, Kennwort mindestens 10 Zeichen, bcrypt-Hash | Verwaltung → Benutzer, `adk:benutzer-anlegen` | Konto mit 9-stelligem Kennwort anlegen: wird abgelehnt | `RolesTest`, `AuthTest` |
| Konten sperrbar ohne Datenverlust | Verwaltung → Benutzer → Sperren | Konto sperren: Person wird sofort abgemeldet, Vorgänge und Protokoll bleiben | `AuthTest` |
| Aufruf nur über HTTPS, HTTP wird umgeleitet | Middleware `SecurityHeaders`, Plesk | `http://crm-test…` aufrufen: 301 auf `https://`, Header `Strict-Transport-Security` | `AuthTest` |
| Zwei Rollen, Konten anlegen und sperren nur durch die Verwaltung | `config/adk.php` → `roles` | Als Mitarbeitende `/benutzer` aufrufen: 403, Menü „Verwaltung“ fehlt | `RolesTest` |
| Protokoll jeder Aktivität, jedes Statuswechsels und jedes Imports mit Benutzer und Zeitpunkt | Verwaltung → Protokoll | Status setzen, danach im Protokoll Bereich „Vorgang“ und „Aktivität“ mit Benutzer, Zeitpunkt, alt/neu | `StatusRulesTest`, `ImportTest` |
| Export nur für angemeldete Benutzer, als Datei auf das eigene Gerät | Vorgänge → Exportieren (nur Verwaltung) | Filter setzen, exportieren: Download XLSX/CSV, Eintrag „Export“ mit Anzahl und Filter im Protokoll | `RolesTest` |
| Eigene Datenbank nur für dieses System | Plesk, `.env` | eigene MariaDB-Datenbank und eigener Benutzer je Umgebung | – |
| Quelle beim Import als Pflichtfeld | Verwaltung → Import | ohne Quelle oder Abrufdatum: Formular lässt nicht weiter, Dienst lehnt ab | `ImportTest` |
| Sperrliste greift beim Import | Verwaltung → Import | Datei mit gesperrter Nummer einspielen: Zeile übersprungen, Grund im Importprotokoll | `ImportTest` |
| Warnung in der Anrufliste | Akquise → Anrufliste | Vorgang mit gesperrter Firma: roter Hinweis „Kein Anruf möglich“, kein `tel:`-Link, Status 1–5 abgelehnt | `CallListTest` |
| Täglicher Löschlauf nach den Fristen, Sperrliste bleibt unberührt | `adk:loeschlauf`, Plesk-Cron | `php artisan adk:loeschlauf --dry-run` listet auf; Protokoll „Löschlauf“ nur mit Anzahlen | `RetentionTest` |
| Häkchen „Datenschutzhinweis wurde übermittelt“ mit Datum | Kontakt, Taste 3 | Taste 3: Datum am Kontakt gesetzt, falls leer | `StatusRulesTest` |
| Felder „Einwilligung am“ und „Nachweis“ für Privatpersonen | Kontakt → Datenschutz und Einwilligungen | Privatperson aus Kaltakquise ohne Einwilligung erscheint nicht in der Anrufliste; mit Datum **und** Nachweis schon | `StatusRulesTest`, `CallListTest` |
| Taste 8 für den Werbewiderspruch | Anrufliste, Vorgang → Status setzen | Taste 8, Enter: Sicherheitsabfrage; nach „Ja“ Vorgang geschlossen, Telefon, E-Mail, Firma + PLZ auf der Sperrliste | `StatusRulesTest`, `CallListTest`, `LeadPageTest` |
| Neu: Zwei-Faktor-Anmeldung | Anmeldung, Profil | Neues Konto: nach dem Kennwort Pflicht zur Einrichtung (QR-Code, Wiederherstellungscodes), ohne Einrichtung kein Zugriff | `AuthTest` |

---

## 3 Was gebaut ist, gegen den Auftrag

### 3.1 Zugang und Sicherheit (Auftrag 3)

- Anmeldung unter `/login`, keine Registrierung, kein Zurücksetzen per E-Mail. Kennwort vergessen: Die Verwaltung setzt ein neues Kennwort.
- TOTP-Pflicht für alle Konten mit 8 Wiederherstellungscodes. Die Verwaltung kann die Zwei-Faktor-Anmeldung zurücksetzen (z. B. bei Verlust des Telefons), das wird protokolliert. Geheimnis und Codes liegen verschlüsselt bzw. gehasht in der Datenbank.
- Rollen und Rechte in `config/adk.php`. Jedes Recht ist ein Gate. Eine dritte Rolle (z. B. „Claude“ nach Lastenheft Abschnitt 12) wird dort ergänzt, ohne Datenbankänderung. Das Recht `health.view` ist für Stufe 4 bereits definiert.
- Gesperrte Konten werden bei der nächsten Anfrage abgemeldet und können sich nicht anmelden.
- Anmeldeversuche: 5 je Minute. Anmeldung, Abmeldung und Fehlversuche stehen im Protokoll.
- Dateien: Importdateien liegen während des Imports in `storage/app/private` und werden danach gelöscht. Es gibt keine öffentlich abrufbaren Dateien mit Daten.

### 3.2 Datenmodell (Auftrag 4)

Tabellen wie vorgegeben, dazu `activity_log` (Protokoll). Telefonnummern als E.164 (`phone_e164`) und Anzeigeform (`phone_display`). Zusätzliche Spalten:

- `organizations.name_normalized`, `website_domain`: Vergleichsformen für die Dublettenerkennung
- `check_levels` und `organization_checks`: frei verwaltbare Prüfstufen und ihre Ergebnisse je Organisation (bestanden, nicht bestanden, offen), dazu `organizations.check_notes`
- `contacts.phone_consent_last_used_at`: für die Frist „5 Jahre ab letzter Verwendung“
- `leads.close_reason` (Grund bei „Datensatz falsch“), `leads.contracted_at` (Vertragsschluss, für Stufe 3 und den Löschlauf), `leads.import_log_id`

### 3.3 Status und Regeln (Auftrag 5)

Umgesetzt in `app/Services/LeadStatusService.php`, gesteuert über `config/adk.php`. Arbeitstage mit bundeseinheitlichen Feiertagen und Rheinland-Pfalz (Fronleichnam, Allerheiligen) in `app/Support/WorkingDays.php`. Jeder Statuswechsel erzeugt eine Aktivität (in der Anrufliste Typ „Anruf“ mit Ergebnis, sonst „Statuswechsel“).

### 3.4 Ansichten (Auftrag 6)

| Ansicht | Adresse | Hinweise |
|---|---|---|
| Heute | `/` | fällig und überfällig, dazu fällige Cross-Selling-Wiedervorlagen; Termine des Tages; neue eingehende Anfragen rot mit Balken oben; Filter „nur meine“ |
| Anrufliste | `/anrufliste` | ein Vorgang, Reihenfolge Priorität A, B, C, dann älteste Wiedervorlage, Vorgänge ohne Datum zuletzt; Schalter „nur meine und nicht zugewiesene“; Überspringen |
| Vorgänge | `/vorgaenge` | alle geforderten Filter, zusätzlich offen/geschlossen und „nur meine“; Suche nach Firma, Ort, PLZ, Name, E-Mail; Export für die Verwaltung |
| Vorgang | `/vorgaenge/{id}` | Vorgang, Organisation, Kontakt mit Einwilligungen, Aktivitäten, Termine; Status setzen, Cross-Selling, Aktivität erfassen |
| Kalender | `/kalender` | Tag und Woche, Termine, Wiedervorlagen, Cross-Selling, Feiertage; „nur meine“ |
| Auswertung | `/auswertung` | Zeitraum und Person wählbar; je Tag und Woche; Quoten je Branche, Kanal, Importquelle; „Datensatz falsch“ je Importquelle |
| Sperrliste | `/sperrliste` | nur Verwaltung; ergänzen; löschen nur mit Begründung (mind. 10 Zeichen), protokolliert |
| Importe | `/importe`, `/import` | Protokoll mit übersprungenen Zeilen und Grund; Import mit Zuordnung und Vorschau |
| Stammdaten | `/organisationen`, `/kontakte` | Pflege der Organisationen (Quelle und Abrufdatum Pflicht) und Kontakte (Einwilligungen) |
| Benutzer | `/benutzer` | nur Verwaltung |
| Protokoll | `/protokoll` | nur Verwaltung, nur lesen, mit alt/neu |

### 3.5 Import und Export (Auftrag 7, 8)

- Mustervorlage: [`docs/import_vorlage.xlsx`](import_vorlage.xlsx), neu erzeugbar mit `php artisan adk:importvorlage`. Download auch auf der Importseite.
- CSV: Trennzeichen (Semikolon, Komma, Tab) und Zeichensatz (UTF-8 oder Windows-1252 aus Excel) werden erkannt.
- Spalten werden über die Überschrift automatisch zugeordnet und lassen sich ändern. Die Vorschau zeigt die ersten fünf Zeilen mit der gewählten Zuordnung.
- Jede importierte Zeile ergibt Organisation, optional Ansprechpartner und einen Vorgang „Neu“, Kanal Kaltakquise, Zielgruppe „Betrieb, Zuordnung offen“.
- Export: aktuelle Filter und Suche, XLSX oder CSV (Semikolon), Protokoll mit Anzahl und Filter.

### 3.6 Protokoll (Auftrag 9)

`spatie/laravel-activitylog`, Modell `App\Models\AuditLog`: Änderungen und Löschungen sind im Code gesperrt. Protokolliert werden Änderungen an Vorgang, Organisation, Kontakt, Termin, Aktivität, Benutzer und Sperrliste (alt/neu), Importe, Exporte, Anmeldung, Abmeldung, Fehlversuche, Zurücksetzen der Zwei-Faktor-Anmeldung und der Löschlauf. Nicht protokolliert werden Kennwörter und Zwei-Faktor-Geheimnisse.

### 3.7 Löschlauf (Auftrag 10)

`php artisan adk:loeschlauf` täglich um 02:30 Uhr, `--dry-run` listet nur auf. Gelöscht wird endgültig, samt den Protokolleinträgen zu den gelöschten Datensätzen. Bei abgelaufenen Einwilligungsnachweisen werden Datum und Nachweis auch aus den Änderungsprotokollen entfernt. Im Protokoll bleibt ein Eintrag „Löschlauf“ mit Anzahlen je Datenart und Zeitpunkt.

### 3.8 Vorbereitung Stufe 2 bis 4 (Auftrag 11)

Nicht gebaut, aber vorbereitet:

- **Website-Formular (Stufe 2):** Vorgänge aus eingehenden Kanälen werden schon heute richtig behandelt (rot, Wiedervorlage heute, Anruf erlaubt). Eine Schnittstelle muss nur `Lead::create()` mit Kanal `website_form` aufrufen. Die Zuordnung Kostenträger → Zielgruppe gehört dann in `config/adk.php`.
- **Postfach info@ und E-Mail-Versand (Stufe 2):** Aktivitätstyp „E-Mail“ existiert. `MAIL_*` in `.env` ist vorgesehen.
- **Förderweg (Stufe 3):** Status „Übergeben an Förderweg“ ist setzbar, `leads.contracted_at` steuert bereits den Löschlauf.
- **Teilnehmerakte (Stufe 4):** Recht `health.view` und die Rollenstruktur sind vorhanden. Hochgeladene Dateien laufen über die private Disk `local` (nicht über `public`).

---

## 4 Abweichungen und Auslegungen

| Nr. | Punkt | Umsetzung | Begründung |
|---|---|---|---|
| 1 | Tasten 0–9 bei Fokus im Notizfeld | Im Notizfeld wählen **Alt+0 bis Alt+9** den Status, außerhalb des Notizfelds genügt die Ziffer. Enter speichert, Umschalt+Enter macht eine neue Zeile | Mit Fokus im Notizfeld könnte man sonst keine Ziffern schreiben (Rückrufnummern, Uhrzeiten) |
| 2 | Taste 3 ohne Kontakt | Hat der Vorgang keinen Kontakt, fragt das CRM nach dem Empfänger (Nachname Pflicht) und legt ihn als Kontakt an | Das Datum „Datenschutzhinweis übermittelt am“ gehört laut Datenmodell zum Kontakt. Unterlagen gehen immer an eine Person |
| 3 | „Vorgang ruhen lassen?“ | Ja = Wiedervorlage in 3 Monaten (`not_reached_rest_months`), Status bleibt „Nicht erreicht“ | Auftrag nennt nur die Frage. Siehe offene Frage 2 |
| 4 | Anrufliste und Sperrliste | Vorgänge mit Sperrlisten-Treffer erscheinen mit Warnung, aber ohne `tel:`-Link. Nur die schließenden Status 6–9 lassen sich setzen | So kann der Vorgang sauber geschlossen werden |
| 5 | Anrufliste und Privatpersonen | Privatpersonen aus der Kaltakquise ohne Einwilligung (Datum **und** Nachweis) erscheinen gar nicht in der Anrufliste | „gesperrt“ konsequent umgesetzt |
| 6 | Stammdaten ohne Vorgang | Der Löschlauf entfernt sie erst nach 7 Tagen ohne Änderung (`orphan_grace_days`) | Sonst würde eine gerade von Hand angelegte Organisation in der Nacht gelöscht |
| 7 | Löschen in der Oberfläche | Vorgänge, Organisationen und Kontakte lassen sich in der Oberfläche nicht löschen, Benutzer nur sperren | Löschen läuft einheitlich und nachvollziehbar über den Löschlauf. Siehe offene Frage 6 |
| 8 | Exportprotokoll | im allgemeinen Protokoll (Bereich „Export“) statt in einer eigenen Tabelle | alle geforderten Angaben enthalten, eine Tabelle weniger |
| 9 | Privatperson | Vorgang ohne Organisation oder mit Kontakt „Privatperson“ | gilt für Anrufsperre und die 6-Monats-Frist |
| 10 | Aufbewahrung Interessenten | Betriebe 24 Monate, Privatpersonen 6 Monate | Empfehlung aus Lastenheft Abschnitt 10 Nr. 1, im Auftrag so vorgegeben. Werte in `config/adk.php` |
| 11 | Beschriftung „Passwort“ | Die Anmeldeseite von Filament schreibt „Passwort“ statt „Kennwort“ | Übersetzung des Pakets. Eigene Texte verwenden „Kennwort“. Siehe offene Frage 9 |
| 12 | Prüfstufen | Nicht fest „Prüfstufe 1 bis 5“ und nicht in `config/adk.php`, sondern von der Verwaltung unter **Verwaltung → Prüfstufen** anlegbar, umbenennbar, sortierbar, abschaltbar und löschbar. Import-Spalten und Mustervorlage folgen den aktiven Prüfstufen | Wunsch aus der Durchsicht vom 27.09.2026. Nachvollziehbarkeit über das Protokoll statt über Git |

---

## 5 Bekannte Grenzen

- **Browser-Skripte:** Filament nutzt Livewire und Alpine. Die Content-Security-Policy erlaubt deshalb `'unsafe-inline'` und `'unsafe-eval'` für Skripte vom eigenen Server. Externe Quellen bleiben gesperrt.
- **Zeitzone:** Europe/Berlin fest eingestellt.
- **Auswertung:** „Anruf“ zählt jedes Speichern in der Anrufliste. „Erreicht“ zählt Anrufe mit einem Ergebnis außer „Nicht erreicht“ und „Datensatz falsch“ (in `config/adk.php`, Schlüssel `reached`). Termine, die über den Reiter „Termine“ statt über Taste 4 angelegt werden, zählen nicht als Anrufergebnis.
- **Sitzung:** Nach 120 Minuten Inaktivität ist eine neue Anmeldung nötig.
- **Wiedervorlage „Neu“ aus Kaltakquise:** Importierte Betriebe haben kein Datum und erscheinen in der Anrufliste nach den fälligen Wiedervorlagen, aber nicht in „Heute“.
- **Einwilligung Gesundheitsangaben:** nur Datum und Nachweis, keine medizinischen Angaben. Die Felder sind für beide Rollen sichtbar.
- **Protokoll der Anmeldungen:** wird vom Löschlauf nicht gekürzt (keine Frist vorgegeben).
- **Oberflächentexte des Pakets:** Einzelne Standardtexte von Filament (z. B. Zwei-Faktor-Einrichtung) kommen aus dessen deutscher Übersetzung.

---

## 6 Offene Fragen an Janosch

1. **Löschfrist Protokoll:** Wie lange sollen Einträge zu Anmeldungen, Exporten und Importen im Protokoll bleiben? **Entschieden 04.10.2026: 3 Jahre ab Ende des Kalenderjahres, der Löschlauf löscht automatisch.**
2. **„Vorgang ruhen lassen“:** Reichen 3 Monate Wiedervorlage? Oder soll der Vorgang als „Kein Interesse“ geschlossen werden (dann beginnt die Löschfrist)?
3. **Gelöschte Sperrlisteneinträge:** Beim Löschen eines Sperrlisteneintrags stehen Telefon, E-Mail und Firma weiter im Protokoll (Nachweis). Soll das so bleiben?
4. **Sichtbarkeit Einwilligung Gesundheitsangaben:** Datum und Nachweis sehen heute beide Rollen. Soll das schon in Stufe 1 nur für die Verwaltung sichtbar sein?
5. **Branchenmatrix und Prüfstufen:** Die Branchenmatrix lag nicht vor. Die zehn Branchen in `config/adk.php` sind Platzhalter mit plausiblen WZ-Codes. Die fünf Prüfstufen heißen vorerst „Prüfstufe 1“ bis „Prüfstufe 5“ und lassen sich unter Verwaltung → Prüfstufen selbst benennen und beschreiben.
6. **Löschen von Hand:** Soll die Verwaltung einzelne Vorgänge sofort löschen können (z. B. bei einem Löschersuchen nach Art. 17 DSGVO)? **Erledigt 04.10.2026: Knopf „Löschersuchen (Art. 17)“, nur Verwaltung.**
7. **Zuständigkeit bei Import:** Importierte Vorgänge sind niemandem zugewiesen und erscheinen allen in der Anrufliste („nur meine und nicht zugewiesene“). Gewünscht?
8. **Verbindliche Uhrzeit Löschlauf:** 02:30 Uhr. Passt das zur Sicherung bei IONOS?
9. **„Passwort“ oder „Kennwort“:** Soll die Anmeldeseite von Filament auf „Kennwort“ umgestellt werden (eigene Übersetzungsdatei)?
