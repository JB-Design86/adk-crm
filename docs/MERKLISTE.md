# ADK CRM · Merkliste

Stand 28.09.2026 · Punkte aus der Durchsicht mit Janosch, damit nichts verloren geht. Erledigtes wird abgehakt, nicht gelöscht.

---

## Jetzt in Arbeit

- [x] **Leadlisten-Vorlage für den Vertriebs-Chat:** Excel-Vorlage mit allen Spalten plus Blatt „Erklärung“ (Bedeutung, erlaubte Werte, Pflicht). Dazu eine Rechercheanleitung, nach der ein KI-Chat Betriebe im Internet sucht und die Vorlage befüllt, sodass sie ohne Umbau importierbar ist.
- [x] **Hilfe-Fragezeichen:** ein **?** mit kurzer Erklärung an Feldern, Spalten und Status, dazu ein Satz je Seite, wofür sie da ist. Texte an einer Stelle gesammelt, damit sie leicht anpassbar sind.
- [x] **Stufe 3 vorziehen: Förderweg** (erste Fassung fertig, siehe `STUFE3_FOERDERWEG.md`; Details unten). Ziel: Vertrieb kann vom ersten Anruf bis zur Anmeldung in einem Zug arbeiten.
- [x] **Automatisches Einspielen auf crm-test** bei jedem Push nach GitHub (Plesk-Webhook), damit dafür keine Plesk-Anmeldung mehr nötig ist. Betrieb bleibt manuell.

- [x] **sipgate, erste Fassung:** Anruf per Klick (Anrufliste, Vorgangsseite) und automatische Protokollierung der Telefonate alle 5 Minuten. Einrichtung siehe `BETRIEB.md` Abschnitt 10. Offen: Einträge in der `.env` von crm-test (Janosch), Test mit eigener Nummer.

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
- Zielgruppe E (Arbeitsunfall): nur Daten und Fristen erfassen (§ 14 SGB IX: zwei Wochen Zuständigkeit, drei Wochen Entscheidung), **keine medizinischen Inhalte**. Einwilligung Gesundheitsangaben ist Voraussetzung. Offen: Lastenheft Abschnitt 10, Nr. 4.

## Stufe 2 · Eingänge automatisch

- **Website-Formular → CRM:** gesicherte Schnittstelle (HTTPS mit Zugangsschlüssel) zur neuen ADK-Website. Jede Anfrage wird sofort Vorgang „Neu“ mit Kanal Website-Formular, das Feld Kostenträger setzt die Zielgruppe.
- **Opt-in für E-Mail:**
  - Im Formular getrennte, nicht vorausgefüllte Häkchen: Rückruf per Telefon, E-Mail-Werbung bzw. Newsletter.
  - Double-Opt-in per Bestätigungslink als Nachweis.
  - Neue Felder am Kontakt: Einwilligung E-Mail am, Double-Opt-in bestätigt am, Nachweis (Formularversion, Zeitpunkt).
  - Abmeldelink bzw. Widerruf.
  - Die Antwort auf die Anfrage selbst braucht kein Opt-in, Werbung schon. Einzelheiten mit der Datenschutz-Beratung klären.
- Datenschutzerklärung der Website ergänzen (Lastenheft 10, Nr. 5). A-11 und A-21 wegen der neuen Schnittstellen anpassen.
- Postfach info@ über Microsoft Graph, E-Mail-Versand aus dem Vorgang, Calendly (Lastenheft 5).
- **sipgate, eingehende Anrufe:** Bei einem Anruf sofort den passenden Vorgang anzeigen (sipgate-Webhook). Unbekannte Nummer: Vorgang „Neu“ mit Kanal Anruf vorschlagen. Verpasste Anrufe als Wiedervorlage für heute.

## Stufe 4 · Teilnehmerakte

- Anlegen erst nach offizieller Einschreibung und Bestätigung durch den Kostenträger (siehe oben).
- Inhalt laut Lastenheft 7: Checkliste Vertrag bis Verbleib, Dokumente verschlüsselt, Gesundheitsangaben nur für die Verwaltung, 10 Jahre Aufbewahrung, ZIP-Ausgabe.

## Konten

- Betrieb: **Verwaltung** (Janosch, arbeitet auch selbst im Vertrieb) und **Mitarbeitende** (Vertriebler, etwa eine Stunde am Tag). Die Konten legt Janosch selbst an, weil dabei Kennwörter vergeben werden.

## Kleinere Punkte

- [ ] Testdaten: fiktive Rufnummern aus den von der Bundesnetzagentur für Medien freigehaltenen Bereichen und Kennzeichen „(Test)“ im Firmennamen, damit niemand versehentlich einen echten Anschluss anruft.
- [ ] Plesk: Header „X-Powered-By: PleskLin“ abschalten.
- [ ] sipgate: AVV prüfen, A-11 und A-21 um den Abruf der Anrufliste ergänzen (`BETRIEB.md` 10.5). Falls ADK sipgate **neo** nutzt, statt `/sessions/calls` den neo-Endpunkt verwenden.
- [ ] Branchenmatrix nachreichen: echte Branchen, WZ-Codes und Prüfstufen.
- [ ] Offene Fragen aus `STUFE1_ABNAHME.md` Abschnitt 6.
