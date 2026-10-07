# ADK CRM · Merkliste

Stand 28.09.2026 · Punkte aus der Durchsicht mit Janosch, damit nichts verloren geht. Erledigtes wird abgehakt, nicht gelöscht.

---

## Jetzt in Arbeit

- [x] **Leadlisten-Vorlage für den Vertriebs-Chat:** Excel-Vorlage mit allen Spalten plus Blatt „Erklärung“ (Bedeutung, erlaubte Werte, Pflicht). Dazu eine Rechercheanleitung, nach der ein KI-Chat Betriebe im Internet sucht und die Vorlage befüllt, sodass sie ohne Umbau importierbar ist.
- [x] **Hilfe-Fragezeichen:** ein **?** mit kurzer Erklärung an Feldern, Spalten und Status, dazu ein Satz je Seite, wofür sie da ist. Texte an einer Stelle gesammelt, damit sie leicht anpassbar sind.
- [x] **Stufe 3 vorziehen: Förderweg** (erste Fassung fertig, siehe `STUFE3_FOERDERWEG.md`; Details unten). Ziel: Vertrieb kann vom ersten Anruf bis zur Anmeldung in einem Zug arbeiten.
- [x] **Automatisches Einspielen auf crm-test** bei jedem Push nach GitHub (Plesk-Webhook), damit dafür keine Plesk-Anmeldung mehr nötig ist. Betrieb bleibt manuell.

- [x] **sipgate im Betrieb eingerichtet** (05.10.2026). Offen: Vereinbarung zur Auftragsverarbeitung mit sipgate abschließen, A-11 und A-21 um sipgate ergänzen, erster Testanruf.
- [x] **sipgate, erste Fassung:** Anruf per Klick (Anrufliste, Vorgangsseite) und automatische Protokollierung der Telefonate alle 5 Minuten. Einrichtung siehe `BETRIEB.md` Abschnitt 10. Offen: Einträge in der `.env` von crm-test (Janosch), Test mit eigener Nummer.

- [x] **Gesamtkonzept Interessent → Förderfall → Teilnehmer** (Durchsicht 04.10.2026), siehe `GESAMTKONZEPT.md`:
  - Vorgänge nach Phase (Akquise, Förderfall, Teilnehmer, geschlossen); übergebene Vorgänge nicht mehr in der Akquise.
  - Dokumente am Vorgang, im Förderfall und in der Akte, verschlüsselt; Pflichtunterlagen vor der Einschreibung.
  - Teilnehmerakte entsteht mit „Einschreibung bestätigt“; Checkliste nach Lastenheft 7.1, Abschluss, Verbleib, ZIP-Ausgabe.
  - Dublettenprüfung gegen den ganzen Bestand (Import, Anlegen von Hand, Bestandsprüfung).

- [x] **Live-System `crm.adk-akademie.de` eingerichtet (05.10.2026)**, ohne Daten, Verwaltungskonto angelegt. Offen: Zwei-Faktor einrichten, APP_KEY in den Kennwortmanager, Backup-Einstellung „Angegebenes Passwort“, IONOS-Sicherung prüfen, Konto Vertrieb.
- [ ] **Sicherung außerhalb des Servers** vor den ersten echten Daten: HiDrive (FTP/SFTP-Upgrade) oder IONOS Cloud Backup, siehe `LIVESCHALTUNG.md` 4.0.
- [ ] Empfehlung Server-Sicherheit (Janosch entscheidet, Claude ändert keine Sicherheitseinstellungen): SSH-Anmeldung als root mit Kennwort abschalten, Zwei-Faktor-Anmeldung für Plesk.
- [ ] **Live-Schaltung** `crm.adk-akademie.de`: Ablauf in `LIVESCHALTUNG.md` (erst klären, dann einrichten ohne echte Daten, dann Konten, dann echte Daten).
- [x] Zielgruppe E (Arbeitsunfall) wird nicht angeboten, im CRM abgeschaltet (Entscheidung 04.10.2026, Lastenheft 10 Nr. 4).
- [x] Protokoll 3 Jahre ab Jahresende, Sicherungen 30 Tage (Entscheidung 04.10.2026).
- [ ] Genauen Serverstandort beim IONOS-Support erfragen und ablegen. Anfrage am 04.10.2026: Standardantwort ohne Standort, Verweis auf die Hotline 0721 170 555; dort telefonisch nachfragen und um Bestätigung per E-Mail bitten. Kundenbereich: „Europa“, AVV: EU bzw. EWR, RIPE: vermutlich Frankreich. Unterlagen sagen „EU“, siehe `LIVESCHALTUNG.md` 1.4.
- [x] Löschersuchen nach Art. 17 DSGVO: Knopf an Organisation, Kontakt und Vorgang (nur Verwaltung), siehe `GESAMTKONZEPT.md` Abschnitt 6.
- [x] **Website adk-akademie.de von df.eu auf den Server umgezogen (06.10.2026)**, siehe `BETRIEB.md` Abschnitt 13. HTTPS, HSTS, Löschlauf, Protokolle 30 Tage; Datenschutzerklärung: IONOS „in der Europäischen Union“.
- [x] **Mailversand vom Server** (06.10.2026): Port 25 von IONOS freigeschaltet, Mail-Dienst der Domain an mit fester Weiterleitung an Microsoft 365 (`BETRIEB.md` Abschnitt 13). Testmail an info@ angenommen.
- [ ] Formulare testen: Kontaktformular und Kursheft mit eigener Adresse (Janosch), Junk-Ordner prüfen. Danach DKIM für `adk-akademie.de` (Schlüssel aus Plesk, TXT-Eintrag bei IONOS).
- [ ] Website: df.eu erst kündigen, wenn die Formulare getestet sind; danach `ip4:92.205.174.49` aus dem SPF-Eintrag nehmen und A-11 nachziehen.
- [ ] Website-Paket: die Anpassungen vom Server ins Paket übernehmen, damit eine neue Fassung sie nicht zurückdreht: `.htaccess` (HTTPS, HSTS), Satz zur EU in `datenschutz.html`, neues Kalenderbild `img/weg-2-quadrat-*`, Kursheft-PDF.
- [x] Website: Kursheft-PDF liegt unter `download/ADK_Kursheft.pdf` (06.10.2026).
- [ ] Website, Scroll-Effekte (Rückmeldung UX, 07.10.2026): Das Anhalten beim Scrollen nervt. Ziel: Parallaxe im Hintergrund läuft weiter, auch während der Abschnitt steht, damit der Flow bleibt. Auf weiterbildung.html unten: Hintergrundbild zu groß gezogen (wirkt verpixelt), Punkte zu groß. Der Abschnitt, bei dem der Hintergrund bewusst als Bild mitwechselt, bleibt wie er ist (Screenshot von Janosch folgt). **Jeden Effekt einzeln mit Janosch durchgehen**: welcher Hintergrund, welche Farbe, passend zu den Nachbarabschnitten. Nichts eigenständig ändern.

## Stufe 3 · Förderweg (vorgezogen)

Ablauf aus Sicht des Vertriebs:

1. **Interessent (Vorgang, Stufe 1):** Akquise mit Anrufliste und Status bis „Interesse vorhanden“ bzw. „Termin vereinbart“.
2. **Zwischenstufe „Förderfall“ („fester Interessent“):** Die Person hat zugesagt. Die ADK begleitet sie durch Antrag und Bewilligung (Beratung, Eignung, Termin bei der Vermittlungsfachkraft, Bildungsgutschein …), fasst nach und hilft bei Anträgen.
3. **Teilnehmer (Stufe 4):** Die Teilnehmerakte wird **erst** angelegt, wenn die Person offiziell eingeschrieben ist und der Kostenträger (z. B. die Agentur für Arbeit) bestätigt hat. Vorher gibt es keine Akte.

Anforderungen:

- Schrittfolge **je Zielgruppe** nach Lastenheft Abschnitt 6 (A/B, C/D, E, Selbstzahler). Jeder Schritt mit Datum, handelnder Stelle und automatischer Wiedervorlage.
- Schritte in der Oberfläche pflegbar (anlegen, umbenennen, sortieren, abschalten), wie die Prüfstufen.
- **Erklärpfade:** Jeder Schritt bekommt einen Text „Was ist jetzt von unserer Seite zu tun?“, der am Förderfall angezeigt wird. Eingebaut, Texte pflegbar unter Verwaltung → Förderweg-Schritte. **Die Texte erarbeitet Janosch.**
- Übersicht: welcher Förderfall steht bei welchem Schritt, was ist überfällig, wo muss nachgefasst werden.
- Auswertung laut Lastenheft: Anfrage → Gutschein beantragt → Gutschein bewilligt, Kursstatistik Prüfpunkt 7.2.
- Zielgruppe E (Arbeitsunfall): nur Daten und Fristen erfassen (§ 14 SGB IX: zwei Wochen Zuständigkeit, drei Wochen Entscheidung), **keine medizinischen Inhalte**. Einwilligung Gesundheitsangaben ist Voraussetzung. Entschieden 04.10.2026: E wird nicht angeboten.

## Stufe 2 · Eingänge automatisch

- [x] **Website-Formular → CRM** gebaut 06.10.2026 (`BETRIEB.md` Abschnitt 14): signierte Schnittstelle `/api/eingang`, Vorgang „Neu“ mit Kanal Website-Formular, Kursheft erst nach Double-Opt-in, Finanzierung setzt die Zielgruppe, „Kein Anruf gewünscht“ sperrt Anrufe. Im Betrieb eingerichtet 06.10.2026 (Schlüssel, Website-Dateien, Cron). Offen: Probe Kursheft mit eigener Adresse, DKIM-Eintrag bei IONOS.
- **Opt-in für E-Mail:**
  - Im Formular getrennte, nicht vorausgefüllte Häkchen: Rückruf per Telefon, E-Mail-Werbung bzw. Newsletter.
  - Double-Opt-in per Bestätigungslink als Nachweis.
  - Neue Felder am Kontakt: Einwilligung E-Mail am, Double-Opt-in bestätigt am, Nachweis (Formularversion, Zeitpunkt).
  - Abmeldelink bzw. Widerruf.
  - Die Antwort auf die Anfrage selbst braucht kein Opt-in, Werbung schon. Einzelheiten mit der Datenschutz-Beratung klären.
- Datenschutzerklärung der Website ergänzen (Lastenheft 10, Nr. 5). A-11 und A-21 wegen der neuen Schnittstellen anpassen.
- Postfach info@ über Microsoft Graph, E-Mail-Versand aus dem Vorgang, Calendly (Lastenheft 5).
- **sipgate, eingehende Anrufe:** Bei einem Anruf sofort den passenden Vorgang anzeigen (sipgate-Webhook). Unbekannte Nummer: Vorgang „Neu“ mit Kanal Anruf vorschlagen. Verpasste Anrufe als Wiedervorlage für heute.

## Stufe 4 · Teilnehmerakte (erste Fassung steht, siehe `GESAMTKONZEPT.md`)

- [ ] Vor dem ersten echten Teilnehmer: A-11, A-21, A-22, A-23, Blatt „Aufbau der Teilnehmerakte“, Handbuch Kapitel 9 anpassen (Lastenheft 10, Nr. 3).
- [ ] Vom System erzeugte Dokumente (A-16, A-26, A-09, Übergabeprotokoll): Vorlagen als Datei nötig.
- [ ] Kennzahlen K1, K2, K7, K8, K10 aus A-04: Definitionen nötig.
- [ ] Erklärtexte je Checklisten-Punkt (Janosch).
- [x] Zugriff Mitarbeitende auf Teilnehmerakten: ja, ohne Gesundheitsangaben (Entscheidung 04.10.2026).
- [x] Pflichtunterlagen vor der Einschreibung: Gutschein, Bewilligung bzw. Kostenzusage, Vertrag (bestätigt 04.10.2026).

- Anlegen erst nach offizieller Einschreibung und Bestätigung durch den Kostenträger (siehe oben).
- Inhalt laut Lastenheft 7: Checkliste Vertrag bis Verbleib, Dokumente verschlüsselt, Gesundheitsangaben nur für die Verwaltung, 10 Jahre Aufbewahrung, ZIP-Ausgabe.

## Konten

- Betrieb: **Verwaltung** (Janosch, arbeitet auch selbst im Vertrieb) und **Mitarbeitende** (Vertriebler, etwa eine Stunde am Tag). Die Konten legt Janosch selbst an, weil dabei Kennwörter vergeben werden.

## Kleinere Punkte

- [ ] Testdaten: fiktive Rufnummern aus den von der Bundesnetzagentur für Medien freigehaltenen Bereichen und Kennzeichen „(Test)“ im Firmennamen, damit niemand versehentlich einen echten Anschluss anruft.
- [x] Plesk: Header „X-Powered-By: PleskLin“ abgeschaltet (serverweit, 05.10.2026).
- [ ] sipgate: AVV prüfen, A-11 und A-21 um den Abruf der Anrufliste ergänzen (`BETRIEB.md` 10.5). Falls ADK sipgate **neo** nutzt, statt `/sessions/calls` den neo-Endpunkt verwenden.
- [ ] Branchenmatrix nachreichen: echte Branchen, WZ-Codes und Prüfstufen.
- [ ] Offene Fragen aus `STUFE1_ABNAHME.md` Abschnitt 6.
