# ADK CRM · Leadliste mit dem Vertriebs-Chat erstellen

Stand 28.09.2026 · Für Janosch Baum und den Vertrieb der ADK

So entsteht eine Leadliste, die sich **ohne Umbau** ins CRM importieren lässt:

1. **Vorlage holen:** im CRM unter **Verwaltung → Import → Mustervorlage herunterladen**. Die Vorlage enthält immer die aktuellen Spalten, auch die Prüfstufen. Eine Kopie liegt als `ADK_CRM_Leadliste_Vorlage.xlsx` im CRM-Ordner.
2. **Chat einrichten:** Dem Vertriebs-Chat die Vorlage und die Anweisung aus Abschnitt 2 geben.
3. **Recherchieren lassen:** am besten in Paketen von etwa 30 bis 50 Betrieben je Region oder Branche.
4. **Kurz prüfen:** Stichproben ansehen, besonders Telefonnummer, Fundstelle und Priorität.
5. **Importieren:** im CRM unter **Verwaltung → Import**. Als **Quelle** z. B. „Vertriebs-Chat Recherche Mainz Steuerberatung“, als **Abrufdatum** das Datum der Recherche. Dubletten und Einträge auf der Sperrliste überspringt das CRM selbst und listet sie im Importprotokoll auf.

---

## 1 Aufbau der Vorlage

| Blatt | Inhalt |
|---|---|
| **Leadliste** | eine Zeile je Betrieb, Spaltenüberschriften nicht ändern. Zwei erfundene Beispielzeilen vor dem Import löschen |
| **Erklärung** | je Spalte: Pflicht ja/nein, Bedeutung, erlaubte Werte, Beispiel. Auch die Prüfstufen sind hier beschrieben |
| **Branchen** | die erlaubten Branchen mit WZ-2008-Code |

Pflicht ist nur der **Firmenname**. Empfohlen sind PLZ, Ort, Telefon, Website, Fundstelle, Branche, Priorität und die Bemerkung. Je vollständiger die Zeile, desto besser funktionieren Dublettenprüfung, Anrufliste und Auswertung.

---

## 2 Anweisung für den Vertriebs-Chat

Diesen Text dem Chat als Anweisung geben, zum Beispiel in den Projekt-Anweisungen. Die Stellen in eckigen Klammern vorher anpassen.

```text
Du recherchierst für die ADK – Akademie für digitale Kompetenz (Mainz) Betriebe,
die für Weiterbildungen ihrer Beschäftigten in Frage kommen. Ergebnis ist eine
ausgefüllte Leadliste im Format der beigefügten Excel-Vorlage.

ZIEL
- Region: [Rhein-Main: Mainz, Wiesbaden, Frankfurt am Main, Darmstadt, Rheinhessen].
- Branchen: nur die im Blatt „Branchen“ genannten, genau so geschrieben.
- Betriebe mit Büro- bzw. Verwaltungsarbeitsplätzen, etwa [5 bis 250] Beschäftigte.
  Keine Ein-Personen-Betriebe, keine Konzernzentralen.

DATENREGELN
- Nur öffentlich zugängliche Geschäftsangaben: Impressum, Website des Betriebs,
  Branchenverzeichnisse, IHK-Angaben.
- Nichts erfinden und nichts schätzen. Ist eine Angabe nicht auffindbar, bleibt die
  Zelle leer.
- Telefon und E-Mail: nur die allgemeinen Kontaktdaten des Betriebs
  (Zentrale, info@). Keine privaten Handynummern oder privaten Adressen.
- Ansprechpartner (Anrede, Vorname, Nachname, Funktion, Durchwahl, E-Mail) nur,
  wenn die Person öffentlich als geschäftlicher Kontakt genannt ist, z. B. die
  Geschäftsführung im Impressum.
- In jede Zeile die Fundstelle (URL) eintragen: die Seite, auf der die Angaben
  stehen, meist das Impressum.

FORMAT
- Eine Zeile je Betrieb im Blatt „Leadliste“. Spaltenüberschriften nicht ändern,
  keine Spalten hinzufügen oder entfernen.
- Die Erklärungen und erlaubten Werte je Spalte stehen im Blatt „Erklärung“.
- PLZ fünfstellig. Mitarbeitende als ganze Zahl (bei Spannen den unteren Wert).
- Ausbildungsbetrieb und Prüfstufen: „ja“, „nein“ oder leer (nicht geprüft).
- Keine Dubletten: denselben Betrieb (gleiche Website oder Telefonnummer) nur einmal.

PRIORITÄT
- A: passende Branche, [10 bis 250] Beschäftigte und ein erkennbarer Anlass,
  z. B. Stellenanzeigen für Büro oder Verwaltung, Ausbildungsbetrieb, Wachstum,
  Umstellung auf digitale Abläufe.
- B: passende Branche und Größe, aber kein erkennbarer Anlass.
- C: passt grundsätzlich, Größe oder Branche unsicher.

BEMERKUNG PRÜFUNG
- Ein bis zwei Sätze: Warum könnte der Betrieb Bedarf an Weiterbildung haben?
  Nur überprüfbare Beobachtungen mit Bezug zur Fundstelle, keine Vermutungen
  über Personen.

ABGABE
- Die ausgefüllte Excel-Datei (Blatt „Leadliste“), dazu eine kurze Übersicht:
  Anzahl Betriebe, genutzte Quellen, Auffälligkeiten.
```

---

## 3 Hinweise zum Datenschutz und zur Ansprache

Das sind Hinweise, keine Rechtsberatung. Bitte mit der Datenschutz-Beratung der ADK abstimmen.

- **Telefonische Kaltakquise bei Betrieben** ist nach § 7 UWG nur bei mutmaßlicher Einwilligung zulässig. Dafür braucht es einen sachlichen Bezug zum Bedarf des Betriebs. Die Spalte **Bemerkung Prüfung** hält fest, warum der Betrieb in Frage kommt, und dient damit auch als Nachweis.
- **Privatpersonen** werden nie aus einer Leadliste angerufen. Das CRM sperrt Anrufe bei Privatpersonen ohne Einwilligung ohnehin.
- **Namentlich genannte Ansprechpersonen** sind personenbezogene Daten. Sie werden über den Datenschutzhinweis informiert, spätestens mit den Unterlagen (Taste 3 in der Anrufliste setzt das Datum). Die Fundstelle beantwortet die Frage „Woher haben Sie meine Daten?“.
- **Werbewiderspruch** (Taste 8) setzt Telefon, E-Mail und Firma dauerhaft auf die Sperrliste. Künftige Importe überspringen diese Betriebe automatisch.
- Für Leadlisten gilt die Löschfrist für Interessenten aus `config/adk.php`: Betriebe 24 Monate nach dem letzten Kontakt.
