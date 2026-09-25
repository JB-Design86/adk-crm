# Auftrag an Claude Code · ADK CRM · Stufe 1 (Leads und Akquise)

Stand 25.09.2026 · Auftraggeber: Janosch Baum, ADK – Akademie für digitale Kompetenz · Grundlage: Lastenheft v0.2 ([ADK_CRM_Lastenheft_v0.2.md](ADK_CRM_Lastenheft_v0.2.md))

Abgelegt als Referenz für die Abnahme. Umsetzung und Abweichungen: [STUFE1_ABNAHME.md](STUFE1_ABNAHME.md).

---

## Auftrag

Du baust das CRM der ADK – Akademie für digitale Kompetenz, eines zugelassenen Bildungsträgers nach AZAV in Mainz. Das vollständige Lastenheft liegt unter `docs/ADK_CRM_Lastenheft_v0.2.md`. Lies es vollständig, bevor du anfängst. Dieser Auftrag umfasst **Stufe 1: Leads und Akquise** plus das Fundament, auf dem die Stufen 2 bis 4 später aufbauen.

### 1 Harte Regeln

1. **Keine echten personenbezogenen Daten.** Du arbeitest ausschließlich mit erfundenen Testdaten (Faker, Locale `de_DE`). Du bekommst keinen Zugang zum Live-Server und zur Live-Datenbank und fragst auch nicht danach.
2. **Keine externen Dienste im Browser.** Keine CDNs, keine Google Fonts von fremden Servern, keine Tracking- oder Analyse-Skripte. Alle Assets liegen lokal im Projekt.
3. **Oberfläche auf Deutsch**, sachlich, Anrede „Sie" in allen Texten an Nutzer. Code, Tabellen- und Variablennamen auf Englisch.
4. **Konfiguration im Code.** Status, Zielgruppen, Eingangskanäle, Fristen und Tastenbelegung stehen in `config/adk.php` und nicht nur in der Datenbank. Änderungen laufen damit nachvollziehbar über Git.
5. Weichst du von diesem Auftrag oder dem Lastenheft ab, begründe es im Commit und in der Abschlussübersicht.

### 2 Technik

| Punkt | Vorgabe |
|---|---|
| Framework | aktuelle stabile Version von Laravel, Verwaltungsoberfläche Filament (aktuelle stabile Hauptversion) |
| PHP | 8.3 oder 8.4 |
| Datenbank | MariaDB bzw. MySQL im Betrieb, SQLite für Tests |
| Tests | Pest |
| Protokoll | spatie/laravel-activitylog oder gleichwertig |
| Import/Export | CSV und XLSX (z. B. openspout oder maatwebsite/excel) |
| Assets | Frontend-Assets werden lokal gebaut und **mit ins Repository committet** (`public/build`). Auf dem Server läuft kein Node |
| Betrieb | Plesk auf Ubuntu 24.04. Auslieferung per Git-Abruf aus GitHub, danach Deploy-Skript |
| Umgebungen | `crm.adk-akademie.de` (Betrieb) und `crm-test.adk-akademie.de` (Test mit erfundenen Daten) |

Halte die Zahl der Zusatzpakete klein. Jedes Paket muss gepflegt und aktuell sein.

### 3 Zugang und Sicherheit

- Anmeldung nur mit eigenem Konto. Kennwort mindestens 10 Zeichen, gespeichert als bcrypt-Hash (Laravel-Standard).
- **Zwei-Faktor-Anmeldung per Authenticator-App (TOTP) ist Pflicht** für alle Konten, mit Wiederherstellungscodes.
- Rollen: **Verwaltung** (alles, inklusive Benutzer anlegen und sperren, Import, Export, Sperrliste bearbeiten) und **Mitarbeitende** (Vorgänge bearbeiten, Anrufliste, eigene Termine; kein Export, kein Import, keine Benutzerverwaltung). Eine dritte Rolle kommt später dazu, das Rollensystem muss erweiterbar sein.
- Konten lassen sich sperren, ohne Daten zu verlieren.
- Nur HTTPS. HTTP wird umgeleitet. Sichere Cookies, strenge Sicherheits-Header.
- Keine öffentliche Registrierung. Kein Zugriff ohne Anmeldung, auch nicht auf Dateien.
- Anmeldeversuche begrenzen.

### 4 Datenmodell

| Tabelle | Inhalt |
|---|---|
| `organizations` | Betrieb oder Einrichtung: Name, Rechtsform, Branche, WZ-2008-Code, Priorität A/B/C, Straße, PLZ, Ort, Telefon, E-Mail, Website, Größe (Mitarbeitende), Ausbildungsbetrieb ja/nein, **Quelle und Abrufdatum (Pflicht)**, Ergebnis der Prüfstufen 1 bis 5 (siehe Lastenheft, Branchenmatrix) |
| `contacts` | Person: Anrede, Vorname, Nachname, Funktion, Telefon, E-Mail, Privatperson ja/nein, Organisation (optional), **Datenschutzhinweis übermittelt am**, **Einwilligung Telefonansprache am + Nachweis** (Pflicht für Anrufe bei Privatpersonen), **Einwilligung Gesundheitsangaben am + Nachweis** (für Zielgruppe E) |
| `leads` | Vorgang: Organisation und/oder Kontakt, Zielgruppe, Eingangskanal, Status, Anzahl Anrufversuche, nächste Aktion am, zuständige Person, Merkmal Cross-Selling JB Design mit eigenem Wiedervorlagedatum, letzter Kontakt am, geschlossen am |
| `activities` | Anruf, E-Mail, Notiz, Statuswechsel, Termin. Jeweils mit Benutzer und Zeitpunkt, Freitext |
| `appointments` | Termin: Vorgang, Datum, Uhrzeit, Art (Telefon, Teams, vor Ort), zuständig |
| `blocklist_entries` | Sperrliste: Telefon (normalisiert), E-Mail, Firmenname + PLZ, Datum, Anlass, eingetragen von. Unbefristet |
| `import_logs` | Dateiname, Quelle, Abrufdatum, Zeilen gesamt, importiert, übersprungen (mit Grund), Dubletten, ausführende Person, Zeitpunkt |
| `users` | Konten mit Rolle, 2FA, gesperrt ja/nein |

Telefonnummern werden normalisiert gespeichert (E.164), zusätzlich in Anzeigeform.

### 5 Zielgruppen, Kanäle, Status

**Zielgruppen** (aus den Aufnahmewegen der ADK):
A Grundsicherung (Jobcenter) · B Arbeitsuchend (Agentur für Arbeit) · C Beschäftigte eines Betriebs (§ 82 SGB III) · D Geschäftsführung eines Betriebs · E Arbeitsunfall (Berufsgenossenschaft, Rentenversicherung) · Selbstzahler · Betrieb, Zuordnung offen

**Eingangskanäle:**
Kaltakquise (Leadliste) · Website-Formular · E-Mail an info@ · Anruf · WhatsApp · Calendly · KURSNET · Empfehlung Vermittlungsfachkraft · persönliches Netzwerk · Google Ads · LinkedIn und Social Media · ChatGPT-Anzeige · Sonstiges

**Status und Regeln:**

| Taste | Status | Regel |
|---|---|---|
| – | Neu | Standard beim Anlegen. Vorgänge aus eingehenden Kanälen (alle außer Kaltakquise) erscheinen **rot und ganz oben**, Wiedervorlage heute |
| 1 | Nicht erreicht | Versuchszähler +1, Wiedervorlage automatisch **zwei Arbeitstage** später. Nach dem dritten Versuch Hinweis „Vorgang ruhen lassen?" |
| 2 | Interesse vorhanden, nachfassen | Wiedervorlagedatum ist Pflicht |
| 3 | Unterlagen versendet | setzt „Datenschutzhinweis übermittelt am" auf heute, falls leer. Wiedervorlage automatisch **fünf Arbeitstage** später |
| 4 | Termin vereinbart | öffnet Termin anlegen (Datum, Uhrzeit, Art). Vorgang erscheint am Termintag in „Heute" |
| 5 | Später Interesse | Wiedervorlagedatum ist Pflicht |
| 6 | Kein Interesse | Vorgang geschlossen |
| 7 | Kein Bedarf | Vorgang geschlossen (falsche Zielgruppe, z. B. Ein-Mann-Betrieb) |
| 8 | **Werbewiderspruch** | Vorgang geschlossen. Telefon, E-Mail und Firma (Name + PLZ) landen auf der Sperrliste. Sicherheitsabfrage vor dem Speichern |
| 9 | Datensatz falsch | Vorgang geschlossen, Grund wählbar (Firma besteht nicht, Nummer falsch, Sonstiges). Zählt in der Auswertung je Importquelle |
| 0 | Cross-Selling JB Design | schaltet das Merkmal um, fragt nach Wiedervorlagedatum. Ändert den Status nicht |
| – | Übergeben an Förderweg | Platzhalter für Stufe 3, in Stufe 1 nur setzbar |

Arbeitstage: Montag bis Freitag, bundeseinheitliche Feiertage plus Rheinland-Pfalz.

Jeder Statuswechsel erzeugt eine Aktivität. Anrufe bei **Privatpersonen** sind in der Anrufliste gesperrt, solange keine Einwilligung eingetragen ist oder der Vorgang aus einem eingehenden Kanal stammt.

### 6 Ansichten

| Ansicht | Inhalt |
|---|---|
| **Heute** (Startseite) | alle offenen Vorgänge mit Wiedervorlage heute oder überfällig, dazu Termine des Tages. Neue eingehende Anfragen rot ganz oben, Überfälliges markiert. Filter: nur meine |
| **Anrufliste** | zeigt **einen Betrieb nach dem anderen** in der Reihenfolge Priorität A, B, C, dann älteste Wiedervorlage. Oben Firmendaten, Telefonnummer als anklickbarer `tel:`-Link, bisherige Aktivitäten. Notizfeld mit Fokus. Tasten 0 bis 9 wie oben, Enter speichert und springt zum nächsten. Warnung, wenn Nummer oder Firma auf der Sperrliste steht (dann kein Anruf möglich) |
| **Vorgänge** | Tabelle mit Filtern (Status, Zielgruppe, Kanal, Priorität, zuständig, Cross-Selling, Branche, Wiedervorlage von/bis), Suche |
| **Vorgang** | alle Daten, Aktivitäten, Termine auf einer Seite |
| **Kalender** | Termine und Wiedervorlagen nach Tag und Woche |
| **Auswertung** | je Tag und Woche: Anrufe, erreicht, Termine, Unterlagen versendet; Quoten je Branche, Kanal, Importquelle; Anzahl „Datensatz falsch" je Importquelle |
| **Sperrliste** | nur Verwaltung: einsehen, von Hand ergänzen. Löschen nur mit Begründung, protokolliert |
| **Importe** | Protokoll aller Importe |

### 7 Import

- Hochladen von CSV oder XLSX. **Quelle und Abrufdatum sind Pflicht**, ohne sie wird nichts eingespielt.
- Spaltenzuordnung mit Vorschau vor dem Einspielen.
- Dubletten: gleiche normalisierte Telefonnummer, gleiche Website-Domain oder gleicher Firmenname mit gleicher PLZ. Dubletten werden übersprungen und im Protokoll aufgeführt.
- **Sperrliste greift automatisch:** betroffene Zeilen werden übersprungen, Grund im Protokoll.
- Jeder Import schreibt ein Importprotokoll.
- Lege eine Mustervorlage `docs/import_vorlage.xlsx` mit den erwarteten Spalten an.

### 8 Export

Nur Rolle Verwaltung. Gefilterte Vorgangsliste als CSV oder XLSX auf das eigene Gerät. Jeder Export wird protokolliert (wer, wann, wie viele Datensätze, welcher Filter).

### 9 Protokoll

Protokolliert werden: jede Aktivität, jeder Statuswechsel, jede Änderung an Organisation, Kontakt und Vorgang (alter und neuer Wert), Importe, Exporte, An- und Abmeldungen, Änderungen an Benutzern und Sperrliste. Das Protokoll ist nur für die Verwaltung einsehbar und nicht änderbar.

### 10 Löschlauf

Täglicher geplanter Befehl `adk:loeschlauf` mit Protokollausgabe. Fristen in `config/adk.php`:

| Datenart | Frist | Beginn |
|---|---|---|
| Vorgang ohne Vertragsschluss, Betrieb | 24 Monate | letzter Kontakt |
| Vorgang ohne Vertragsschluss, Privatperson | 6 Monate | letzter Kontakt |
| Gesprächsnotizen und Aktivitäten | mit dem Vorgang | – |
| Organisationen und Kontakte | wenn kein Vorgang mehr darauf verweist | – |
| Sperrliste | unbefristet | – |
| Nachweis Einwilligung Telefonansprache | 5 Jahre | Erteilung bzw. letzte Verwendung |
| Importprotokoll | 3 Jahre | Ende des Kalenderjahres |

Der Löschlauf hat einen Probelauf-Modus (`--dry-run`), der nur auflistet. Gelöscht wird endgültig, im Protokoll bleibt nur Anzahl und Zeitpunkt ohne Personendaten.

### 11 Nicht bauen, aber vorbereiten

Diese Teile kommen in Stufe 2 bis 4. Baue sie jetzt nicht, verbaue sie aber auch nicht:

- gesicherte Schnittstelle, über die das Website-Formular Anfragen anlegt (Stufe 2)
- Abruf des Postfachs info@ über Microsoft Graph, E-Mail-Versand aus dem Vorgang (Stufe 2)
- Förderweg je Zielgruppe mit Schrittfolge und Fristen (Stufe 3)
- Teilnehmerakte mit verschlüsselt gespeicherten Dokumenten, erzeugten PDFs und Gesundheitsangaben in einem eigenen, nur für die Verwaltung sichtbaren Bereich (Stufe 4)

### 12 Testdaten

Seeder mit erfundenen Daten: rund 300 Betriebe aus Rhein-Main in 10 Branchen mit Prioritäten A/B/C, 30 Privatpersonen aus eingehenden Kanälen, Vorgänge in allen Status, Aktivitäten und Termine über die letzten vier Wochen, 5 Sperrlisteneinträge. Zwei Testkonten (Verwaltung, Mitarbeitende) mit dokumentierten Zugangsdaten nur für die Testumgebung. Der Seeder läuft in der Betriebsumgebung nie.

### 13 Tests

Pest-Tests mindestens für: jede Statusregel samt automatischer Wiedervorlage und Arbeitstagen, Taste 8 und Sperrliste, Sperre für Anrufe bei Privatpersonen ohne Einwilligung, Import (Pflichtfelder, Dubletten, Sperrliste, Protokoll), Rollenrechte (Mitarbeitende können nicht exportieren, importieren, Benutzer verwalten), Löschlauf mit allen Fristen und Probelauf, Zwei-Faktor-Pflicht.

### 14 Auslieferung auf Plesk

Lege `docs/BETRIEB.md` an mit:

- Plesk-Einstellungen: Dokumentenstamm `public`, PHP-Version, benötigte PHP-Erweiterungen
- `.env.example` mit allen Schlüsseln, ohne echte Werte
- Deploy-Skript für die Git-Erweiterung in Plesk: `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `php artisan optimize`, Storage-Link
- geplante Aufgabe in Plesk: `php artisan schedule:run` jede Minute
- Ersteinrichtung: erstes Verwaltungskonto über einen Artisan-Befehl anlegen
- Sicherung: welche Verzeichnisse und die Datenbank

### 15 Arbeitsweise

- Kleine, beschreibende Commits.
- Zuerst Fundament (Anmeldung, 2FA, Rollen, Protokoll, Datenmodell), dann Import und Sperrliste, dann Anrufliste und Status, dann Heute, Kalender, Auswertung, dann Löschlauf.
- Am Ende eine Übersicht in `docs/STUFE1_ABNAHME.md`: was gebaut ist, wie man es prüft, bekannte Grenzen, offene Fragen.

### 16 Abnahme

Die Abnahme erfolgt auf `crm-test.adk-akademie.de` mit den Testdaten gegen Abschnitt 9 des Lastenhefts („Was die CRM-Datenschutzunterlagen bereits zusagen") und gegen diesen Auftrag. Jede dort zugesagte Funktion muss nachweisbar vorhanden sein.
