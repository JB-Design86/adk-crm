# ADK CRM · Lastenheft und Ausbaustufen

Version 0.2 · Stand 24.09.2026 · Verantwortlich: Janosch Baum, Trägerleitung

Neu in 0.2: Teilnehmerakte vollständig im CRM (Abschnitt 7) · Serveraufteilung in Betriebs- und Webserver (2.1) · Claude Team als offene Entscheidung (10, Nr. 8) · Zugänge für Claude (12)

Mitgeltend: CRM-Datenschutzunterlagen vom 09.09.2026 · A-11 Verarbeitungsverzeichnis v1.3 · A-21 TOM · A-22 Löschkonzept · Aufnahmewege A bis E · IT-Struktur und Betriebsaufbau, Teil 5 (Teilnehmerweg) · Branchenmatrix Leadliste · Aufbau der Teilnehmerakte

Grundlage ist das Diktat vom 24.09.2026. Stand dieser Fassung: Struktur. Der Volltext je Stufe entsteht als Auftrag für Claude Code, sobald die offenen Entscheidungen in Abschnitt 10 gefallen sind.

---

## 1 Zweck

Ein System für den ganzen Weg eines Kontakts: Erstkontakt, Akquise, Förderweg, Anmeldung, Kursdurchführung, Verbleib sechs Monate nach Kursende. Die Nachweise für AZAV und Kostenträger entstehen dabei im laufenden Betrieb. Die Leadliste für die Telefonakquise bei Betrieben ist der erste Einsatz, eingehende Anfragen aller anderen Kanäle laufen in dieselbe Liste.

---

## 2 Betrieb und Technik

| Punkt | Festlegung |
|---|---|
| Server | Betriebsserver: IONOS VPS Linux L+ · 4 vCores · 8 GB RAM · 240 GB NVMe · nur interne Systeme (CRM, später Moodle). Websites laufen auf einem eigenen Server, siehe 2.1 |
| Standort | Rechenzentrum in Deutschland (Zusage in A-11 und den CRM-Datenschutzunterlagen) |
| Verwaltung | Plesk, Betriebssystem Ubuntu 24.04 LTS |
| Adressen | `crm.adk-akademie.de` für den Betrieb · `crm-test.adk-akademie.de` mit erfundenen Daten für Abnahme und Schulung · DNS liegt bereits bei IONOS |
| Software | PHP mit Laravel, Verwaltungsoberfläche Filament, Datenbank MariaDB. Verbreiteter Standard, den jeder PHP-Entwickler weiterpflegen kann |
| Code | privates GitHub-Repository · Entwicklung mit Claude Code im Code-Bereich der Claude-App · Auslieferung auf den Server über den Git-Abruf in Plesk |
| Datenregel | Bis zum Wechsel auf Claude Team mit AVV (Abschnitt 10, Nr. 8): Claude arbeitet mit Code und erfundenen Testdaten, ohne Zugang zur Live-Datenbank und ohne SSH-Zugang zum Live-Server. Danach gilt Abschnitt 12 |
| Anmeldung | eigenes Konto je Person · Zwei-Faktor-Anmeldung über Authenticator-App · Rollen Verwaltung und Mitarbeitende |
| Sicherung | IONOS Backup, getrennt vom Server · täglich (A-21 verlangt mindestens wöchentlich) · Wiederherstellung einmal im Jahr erproben |

**Kosten laut IONOS-Tarifseite (Stand 24.09.2026):**

| Posten | Betrag |
|---|---|
| VPS L+ | 7 € im Monat für drei Monate, danach 22 € im Monat |
| Einrichtung | 10 € einmalig |
| Plesk | 5 € im Monat |
| Backup | 0,06 € je GB und Monat, bei 50 GB rund 3 € |
| **Erstes Jahr** | **rund 325 €** |
| **ab dem zweiten Jahr** | **rund 30 € im Monat** |

MwSt.-Ausweis im Warenkorb prüfen. Wird die ADK nach § 4 Nr. 21 UStG befreit, entfällt der Vorsteuerabzug, dann zählt der Bruttobetrag.

### 2.1 Serveraufteilung

| Server | Inhalt | Tarif |
|---|---|---|
| Betriebsserver | CRM mit Teilnehmerakten, Testinstanz, später Moodle | VPS L+ |
| Webserver | ADK-Website, Websites der Agenturkunden | Start mit VPS L+, ab rund 25 WordPress-Seiten VPS XL+ (8 vCores, 16 GB RAM, 480 GB NVMe, 41 € im Monat) |

Begründung der Trennung: Auf dem Betriebsserver liegen Teilnehmerakten mit Gesundheitsangaben (Aufnahmeweg E, Nachteilsausgleich). Dreißig bis fünfzig Kundenwebsites mit ihren Plugins sind die größte Angriffsfläche im ganzen Betrieb. Eine Lücke in einer Kundenwebsite erreicht die Akten damit nicht. Wartung, Updates und Lastspitzen der Kundenseiten stören den Unterricht nicht. Zwei L+ kosten ungefähr so viel wie ein XL+.

Plesk wird bei IONOS für VPS als Web Host Edition angeboten, damit ist die Zahl der Domains unbegrenzt. Der Umzug auf einen größeren Server läuft über den Plesk Migrator. Kundenpostfächer bleiben bei einem Mailanbieter und kommen nicht auf den Webserver.

---

## 3 Datenmodell

| Objekt | Inhalt |
|---|---|
| Organisation | Betrieb, Arbeitsagentur, Jobcenter, Reha-Träger. Firmenname, Branche und WZ 2008 aus der Branchenmatrix, Priorität A/B/C, Anschrift, Telefon, Website, Größe, Ausbildungsbetrieb ja/nein, Quelle und Abrufdatum (Pflicht), Ergebnis der Prüfstufen 1 bis 5 |
| Person | Anrede, Name, Funktion, Kontaktdaten, Einwilligung in Telefonansprache (Datum, Nachweis), Datenschutzhinweis übermittelt am |
| Vorgang | ein Anlass je Person oder Betrieb: Zielgruppe, Eingangskanal, Status, nächste Aktion am, zuständig, Notizen, Merkmal Cross-Selling |
| Aktivität | Anruf, E-Mail, Termin, Notiz, Statuswechsel. Jeweils mit Benutzer und Zeitpunkt |
| Wiedervorlage | Datum und Anlass. Steuert die Tagesansicht |
| Sperrliste | Telefon, E-Mail, Firma. Unbefristet |
| Importprotokoll | Dateiname, Quelle, Zeilenzahl, übersprungene Zeilen, ausführende Person |
| Teilnehmer | ab Vertragsschluss, siehe Stufe 4 und Entscheidung 3 |
| Durchlauf | Starttermin, Ende, Plätze, zugeordnete Teilnehmer |

---

## 4 Stufe 1 · Leads und Akquise

Ziel: einsatzbereit, bevor die Telefonakquise startet.

### 4.1 Zielgruppe

Die Einteilung folgt den fünf Aufnahmewegen im Vertriebsordner.

| Zielgruppe | Kostenträger |
|---|---|
| A · Grundsicherung | Jobcenter |
| B · Arbeitsuchend | Agentur für Arbeit |
| C · Beschäftigte eines Betriebs | Agentur für Arbeit über § 82 SGB III, Antrag durch den Betrieb |
| D · Geschäftsführung eines Betriebs | wie C oder Selbstzahler |
| E · Arbeitsunfall | Berufsgenossenschaft, nachrangig Rentenversicherung |
| Selbstzahler | Person oder Betrieb |
| Betrieb, Zuordnung offen | Kaltakquise vor dem ersten Gespräch; nach dem Gespräch C, D oder Cross-Selling |

### 4.2 Eingangskanal

Kaltakquise (Leadliste) · Website-Formular · E-Mail an info@ · Anruf · WhatsApp · Calendly · KURSNET · Empfehlung einer Vermittlungsfachkraft · persönliches Netzwerk · Google Ads · LinkedIn und Social Media · ChatGPT-Anzeige · Sonstiges

### 4.3 Status

Entwurf aus dem Diktat. Drei Stufen sind für die Telefonpraxis ergänzt (Nicht erreicht, Kein Bedarf, Datensatz falsch).

| Status | Bedeutung | Wiedervorlage |
|---|---|---|
| Neu | noch nicht bearbeitet. Eingehende Anfragen erscheinen rot ganz oben | Rückruf am selben Arbeitstag |
| Nicht erreicht | Versuch 1, 2, 3 wird mitgezählt | automatisch zwei Arbeitstage später |
| Interesse vorhanden, nachfassen | Gespräch geführt, nächster Schritt offen | Datum ist Pflicht |
| Unterlagen versendet | mit Datenschutzhinweis, Häkchen und Datum werden gesetzt | automatisch fünf Arbeitstage später |
| Termin vereinbart | Datum, Uhrzeit, Art (Telefon, Teams) | am Termintag |
| Später Interesse | zum Beispiel nächster Durchlauf | Datum ist Pflicht |
| Kein Interesse | Vorgang geschlossen, Löschfrist läuft | keine |
| Kein Bedarf | falsche Zielgruppe, etwa Ein-Mann-Betrieb oder kein Büro | keine |
| Datensatz falsch | Firma besteht nicht, Nummer falsch. Fließt als Rückmeldung in die Prüfstufen der Leadliste | keine |
| Werbewiderspruch | Taste 8. Vorgang geschlossen, Telefon, E-Mail und Firma auf die Sperrliste | keine |
| Übergeben an Förderweg | weiter in Stufe 3 | nach Schritt |

**Cross-Selling JB Design** läuft als eigenes Merkmal neben dem Status, mit eigener Wiedervorlage. Ein Betrieb kann die Weiterbildung ablehnen und die Anzeigenbetreuung wollen. Ein Status allein würde eine der beiden Informationen überschreiben.

### 4.4 Ansichten

| Ansicht | Inhalt |
|---|---|
| Heute | alles mit Wiedervorlage heute und alles Überfällige, neue Anfragen rot oben. Ein Termin für Mittwoch erscheint am Mittwoch hier |
| Anrufliste | nächster Betrieb nach Priorität A, B, C. Bedienung über Tasten: Status setzen, Notiz, Wiedervorlage, Taste 8 |
| Kalender | Termine und Wiedervorlagen nach Tag und Woche |
| Vorgang | alle Daten, Aktivitäten und Termine eines Kontakts auf einer Seite |
| Auswertung | Anrufe je Tag, erreicht, Termine, Quoten je Branche und Kanal |

### 4.5 Import

Excel oder CSV aus der Leadliste. Quelle und Abrufdatum sind Pflicht, ohne sie wird nichts eingespielt. Die Sperrliste greift automatisch. Dubletten werden über Telefonnummer, Website sowie Firmenname mit Postleitzahl erkannt. Jeder Import wird protokolliert. Die Spalten der Leadliste werden so angelegt, dass sie ohne Umbau importierbar sind.

---

## 5 Stufe 2 · Eingänge automatisch, E-Mail aus dem System

| Eingang | Umsetzung |
|---|---|
| Website-Formular | Das Formular der ADK-Website (`anmeldung.php`, später auf dem Webserver) übergibt jede Anfrage über eine gesicherte Schnittstelle (HTTPS mit Zugangsschlüssel) an das CRM. Vorgang „Neu", rot, Kanal Website. Das Feld Kostenträger setzt die Zielgruppe (Jobcenter → A, Agentur für Arbeit → B, Berufsgenossenschaft oder Rentenversicherung → E, Arbeitgeber → C, Selbstzahler) |
| Postfach info@ | liegt in Exchange Online. Abruf über Microsoft Graph mit einer App-Registrierung im Microsoft-365-Konto, Leserecht nur für info@. Unbekannter Absender ergibt einen neuen Vorgang „Neu, E-Mail", bekannter Absender eine Aktivität am bestehenden Vorgang |
| Calendly | Buchung erzeugt einen Termin, sofern der Calendly-Tarif Webhooks enthält (prüfen) |
| WhatsApp | von Hand als Aktivität |
| E-Mail-Versand | aus dem Vorgang über info@, damit jede Mail im Postfach unter Gesendet liegt. Vorlagen: Unterlagen mit Datenschutzhinweis, Nachfassen, Terminbestätigung |

Mit dieser Stufe bekommt das CRM Schnittstellen nach außen. A-21 und die CRM-Unterlagen sagen bisher das Gegenteil und werden vorher angepasst.

---

## 6 Stufe 3 · Förderweg bis zur Anmeldung

Je Aufnahmeweg eine feste Schrittfolge aus den Aufnahmeweg-Blättern. Jeder Schritt hat Datum, handelnde Stelle und eine automatische Wiedervorlage.

| Weg | Schritte |
|---|---|
| A, B | Beratungsgespräch · Eignung A-06 · Informationsblatt A-26 versendet · Termin bei der Vermittlungsfachkraft · Bildungsgutschein beantragt · bewilligt oder abgelehnt, gültig bis · eingelöst · Vertrag |
| C, D | Gespräch mit dem Betrieb · Angebot · Antrag beim Arbeitgeber-Service · Bewilligung · Vertrag |
| E | Sachverhalt geklärt · formloser Antrag gestellt am · Fristen nach § 14 SGB IX automatisch (zwei Wochen Zuständigkeit, drei Wochen Entscheidung) · ärztliche Bescheinigung · Kontakt Reha-Management mit schriftlicher Einwilligung · Maßnahmenpaket versendet · Kostenzusage · Vertrag |
| Selbstzahler | Angebot mit Ratenplan · Vertrag |

**Umwandlung:** Der Vorgang wird zum Teilnehmer oder zum Firmenkunden. Dazu kommen die Stammdaten für Vertrag und Bildungsgutschein: Geburtsdatum, Anschrift, Kundennummer beim Kostenträger, Gutscheinnummer.

**Auswertung:** Kursstatistik nach Prüfpunkt 7.2 (Anfragen, Beratungen, Eignung, Eintritte) und die eigenen Quoten Anfrage → Gutschein beantragt → Gutschein bewilligt. Damit ersetzen nach zwanzig Vorgängen eigene Werte die Annahmen der Zielgruppenanalyse.

---

## 7 Stufe 4 · Teilnehmerakte und Nachweise

Entschieden am 24.09.2026: Die Teilnehmerakte wird vollständig im CRM geführt, mit Daten und Dokumenten. SharePoint entfällt als Ablage für Teilnehmerakten. Es gibt eine Akte und keine zweite Datenquelle.

### 7.1 Checkliste

Die Phasen 5 bis 9 des Teilnehmerwegs als Checkliste je Teilnehmer. Jeder Punkt mit Datum und dem zugehörigen Dokument in der Akte.

| Phase | Punkte |
|---|---|
| Vertrag | Schulungsvertrag A-16 · Datenschutzhinweis A-23 bestätigt · Einwilligung Verbleibserhebung · Leihgerät mit Übergabeprotokoll · Anmeldung beim Kostenträger bestätigt |
| Eintritt | Eintrittsmeldung · Zugang Lernplattform · Technikprobe |
| Durchführung | Zwischengespräche und Zwischenbefragungen nach Fachteil 1 und 2 · Fehlzeitenmeldungen |
| Abschluss | Abschlussleistung A-08 · Zertifikat oder Teilnahmebescheinigung A-09 · Abschlussbefragung A-17 · Austrittsmeldung · Rückgabe Leihgerät · Zugänge gesperrt |
| Verbleib | Wiedervorlage automatisch sechs Monate nach Kursende · Nachbefragung versendet · Verbleibsstatus |

**Durchlauf:** Die Kennzahlen K1, K2, K7, K8 und K10 aus A-04 rechnet das System aus den Checklisten. K3 folgt, wenn die Anwesenheit angebunden ist.

### 7.2 Dokumente in der Akte

| Art | Umsetzung |
|---|---|
| Vom System erzeugt | Schulungsvertrag A-16 mit Anlagen, Informationsblatt A-26, Zertifikat und Teilnahmebescheinigung A-09, Übergabeprotokoll Leihgerät. Die Vorlagen werden aus den Stammdaten befüllt und als PDF in der Akte abgelegt |
| Hochgeladen | unterschriebene Fassungen als Scan, Bildungsgutschein, Kostenzusage, Schriftverkehr mit dem Kostenträger |
| Aus anderen Systemen | Bewertungsbögen A-08 und Protokolle der Zwischengespräche als PDF. Die Werkstücke selbst bleiben in der Lernplattform |
| Gesundheitsangaben | eigener Bereich der Akte (Aufnahmeweg E, Nachteilsausgleich), sichtbar nur für die Rolle Verwaltung |

**Aufbewahrung und Ausgabe:** Die Akte wird zehn Jahre nach Ende der Maßnahme aufbewahrt (A-22). Jede Akte lässt sich vollständig als ZIP mit PDF-Übersicht ausgeben. Damit bleibt sie auch dann lesbar, wenn das System in zehn Jahren abgelöst ist. Die Dokumente liegen verschlüsselt auf dem Server, jeder Abruf wird protokolliert.

---

## 8 Stufe 5 · später

Moodle-Anbindung (Konten anlegen und sperren) · Chatbot auf der Website · Umzug der ADK-Website und der Agenturkunden auf den Webserver · Umzug von Moodle nach dem Pilotdurchlauf · lokale KI auf eigenem Server mit Grafikkarte als eigenes Vorhaben

---

## 9 Was die CRM-Datenschutzunterlagen bereits zusagen

Diese Funktionen stehen in VVT, TOM und Löschkonzept des CRM und sind deshalb Pflicht ab Stufe 1. Fehlt eine davon, beschreibt die Dokumentation eine Maßnahme, die es nicht gibt.

- eigenes Konto je Person, Kennwort mindestens zehn Zeichen, gespeichert als bcrypt-Hash, Konten sperrbar ohne Datenverlust
- Aufruf nur über HTTPS, HTTP wird umgeleitet
- zwei Rollen, Konten anlegen und sperren nur durch die Verwaltung
- Protokoll jeder Aktivität, jedes Statuswechsels und jedes Imports mit Benutzer und Zeitpunkt
- Export nur für angemeldete Benutzer, als Datei auf das eigene Gerät
- eigene Datenbank nur für dieses System
- Quelle beim Import als Pflichtfeld, Sperrliste greift beim Import, Warnung in der Anrufliste
- täglicher Löschlauf nach den Fristen des Löschkonzepts, Sperrliste bleibt unberührt
- Häkchen „Datenschutzhinweis wurde übermittelt" mit Datum
- Felder „Einwilligung am" und „Nachweis" für Privatpersonen
- Taste 8 für den Werbewiderspruch

Neu dazu: Zwei-Faktor-Anmeldung. Sie kommt in A-21.

---

## 10 Offen, vor den ersten echten Daten

| Nr. | Punkt | Wer |
|---|---|---|
| 1 | **Aufbewahrung Interessenten.** A-22 und A-11 (V02) sagen sechs Monate nach letztem Kontakt, die CRM-Unterlagen 24 Monate. Der Status „Später Interesse" braucht mehr als sechs Monate. Empfehlung: Betriebe 24 Monate, Privatpersonen sechs Monate | Janosch entscheidet |
| 2 | **Das CRM fehlt in A-11.** Eigene Verarbeitungstätigkeit anlegen, IONOS als Auftragsverarbeiter um „Hosting CRM" erweitern, A-21 um Server, Zwei-Faktor und Schnittstellen ergänzen, A-22 um die CRM-Zeile | Claude formuliert nach Entscheidung 1 |
| 3 | **Teilnehmerakte im CRM, entschieden 24.09.2026.** Anzupassen vor dem ersten Teilnehmer: A-11 (V02, V03, V05, V09), A-21, A-22, A-23, Blatt „Aufbau der Teilnehmerakte", Handbuch Kapitel 9. Eintrag ins Änderungsprotokoll und in die KVP-Liste A-15, Mitteilung an GüteZert per E-Mail | Claude formuliert, Janosch prüft |
| 4 | **Aufnahmeweg E.** Der Kostenträger Berufsgenossenschaft zeigt einen Arbeitsunfall an, das sind Gesundheitsdaten nach Art. 9 DSGVO. Die CRM-Unterlagen schließen Art. 9 bisher aus. Empfehlung: ausdrückliche Einwilligung im Erstgespräch als Feld im CRM, keine medizinischen Angaben in Notizen, VVT ergänzen wie bei V03 | Janosch entscheidet |
| 5 | Datenschutzerklärung der Website ergänzen, sobald das Formular ins CRM schreibt | mit Stufe 2 |
| 6 | Standort Deutschland bei der Bestellung prüfen | Janosch |
| 7 | GitHub-Konto für die ADK anlegen, falls keines besteht | Janosch |
| 8 | **Claude Team mit AVV.** Mindestens zwei Plätze. AVV mit Standardvertragsklauseln ist Teil der Geschäftsbedingungen. Folge für die Unterlagen: Anthropic als Auftragsverarbeiter mit Übermittlung in die USA in A-11, A-21 und A-23. Das bestehende Konto lässt sich mit derselben E-Mail-Adresse in die Team-Organisation übernehmen, samt Chats, Projekten und Erinnerungen. Der Umzug ist endgültig und nimmt alles mit, was im gemeinsam genutzten Konto liegt | Janosch entscheidet |
| 9 | Kundenpostfächer der Agentur: neuen Mailanbieter festlegen, bevor Websites von DomainFactory wegziehen | Janosch |

---

## 11 Server bestellen

Zuerst der Betriebsserver. Der Webserver folgt, wenn die neue ADK-Website steht. Dann ziehen die Kundenwebsites nacheinander von DomainFactory um.

1. [ionos.de/server/vps](https://www.ionos.de/server/vps) aufrufen, Tarif **VPS L+** wählen.
2. Standort des Rechenzentrums: EU bzw. Deutschland. Im Konfigurator prüfen, dass ein deutscher Standort angezeigt wird. Steht dort nur „EU", beim IONOS-Support den Standort erfragen und die Antwort ablegen.
3. Betriebssystem Ubuntu 24.04 LTS, dazu **Plesk**.
4. **Backup** mitbuchen, 50 GB.
5. Nach der Bereitstellung: in der IONOS-DNS-Verwaltung je einen A-Eintrag für `crm` und `crm-test` auf die Server-IP setzen. Keine Änderung an MX- und SPF-Einträgen.
6. In Plesk beide Subdomains anlegen, SSL-Zertifikat über Let's Encrypt aktivieren.
7. Zugangsdaten von Server und Plesk in den Passwortmanager.

Danach folgt der Auftrag für Claude Code zu Stufe 1.

---

## 12 Zugänge für Claude

Gilt ab dem Wechsel auf Claude Team mit AVV (Abschnitt 10, Nr. 8). Bis dahin arbeitet Claude nur mit Code, Testinstanz und Moodle ohne eingeschriebene Teilnehmende.

| System | Zugang |
|---|---|
| CRM | eigenes Konto mit eigener Rolle. Alle Vorgänge und Akten, ohne den Bereich Gesundheitsangaben. Jede Aktion steht im Protokoll unter dem Namen Claude |
| Moodle | eigenes Konto als Manager |
| Websites | eigenes Administratorkonto je Website |
| Plesk | eigener Benutzer |
| Code | Änderungen am CRM laufen weiter über GitHub und werden in Plesk eingespielt. So bleibt jede Änderung nachvollziehbar |
| Anmeldung | Janosch meldet Claude im Browser an und gibt die Passwörter selbst ein. Claude arbeitet in der angemeldeten Sitzung |

Ein eigenes Konto hat einen Vorteil gegenüber dem Konto der Trägerleitung: Im Protokoll ist jederzeit belegbar, was Claude geändert hat und was ein Mensch.
