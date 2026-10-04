# ADK CRM · Gesamtkonzept: vom ersten Anruf bis zum Verbleib

Stand 04.10.2026 · nach der Durchsicht von Janosch (Förderfall durchgeklickt). Ergänzt Lastenheft v0.2, Abschnitte 4 bis 7.

---

## 1 Ein Datensatz, drei Phasen

Es gibt **eine** Akte je Person bzw. Betrieb und keine zweite Datenquelle. Der Vorgang wandert durch drei Phasen und nimmt alles mit: Verlauf, Termine, Dokumente.

| Phase | Was es ist | Wo es im CRM steht | Wie es weitergeht |
|---|---|---|---|
| **Interessent** (Akquise) | Anfrage oder Betrieb aus der Leadliste | Vorgänge → Reiter **Akquise**, Anrufliste, Heute | Status „Übergeben an Förderweg“ |
| **Förderfall** („fester Interessent“) | Person bzw. Betrieb hat zugesagt, ADK begleitet durch Antrag und Bewilligung | **Förderfälle**, Vorgänge → Reiter **Förderfall**, Heute (nächster Schritt) | alle Schritte erledigt, Pflichtunterlagen da, „Einschreibung bestätigt“ |
| **Teilnehmer** | offiziell eingeschrieben, vom Kostenträger bestätigt | **Teilnehmerakten**, Vorgänge → Reiter **Teilnehmer** | Checkliste bis Verbleib |

- Ein übergebener Vorgang steht **nicht mehr in der Akquise** und nicht mehr in der Anrufliste. Er bleibt über die Reiter auffindbar, damit der Verlauf nachvollziehbar ist.
- Abgebrochene Förderfälle gehen zurück in die Akquise („Später Interesse“ mit Wiedervorlage) oder werden geschlossen.

## 2 Übergang zum Teilnehmer

„Einschreibung bestätigt“ im Förderfall geht erst, wenn

1. alle Schritte des Förderwegs erledigt sind und
2. alle **Pflichtunterlagen** im Vorgang liegen: Bildungsgutschein bzw. Bewilligung oder Kostenzusage und der unterschriebene Schulungsvertrag (bestätigt 04.10.2026). Welche Unterlage Pflicht ist, stellt die Verwaltung je Schritt ein (Verwaltung → Förderweg-Schritte, „Pflicht vor der Einschreibung“).

Dann legt das CRM die **Teilnehmerakte** an (Nummer TN-Jahr-laufend):

- **Bildungsgutschein, Arbeitsunfall, Selbstzahler:** eine Akte für die Person. Kurs, Geburtsdatum und Anschrift können gleich im Dialog eingetragen werden, sonst später in der Akte. Fehlt etwas für den Vertrag, zeigt die Akte einen Hinweis.
- **Betrieb (§ 82 SGB III):** Der Betrieb wird **Firmenkunde**. Die Beschäftigten legt man danach im Förderfall einzeln an („Teilnehmer/in anlegen“), jede Person bekommt ihre eigene Akte.
- Automatisch abgehakt: „Anmeldung beim Kostenträger bestätigt“ und, wenn der Vertrag im Förderfall liegt, „Schulungsvertrag A-16 unterschrieben“ samt Verknüpfung zum Dokument.

## 3 Teilnehmerakte (Stufe 4, erste Fassung)

- **Checkliste** nach Lastenheft 7.1 in fünf Phasen: Vertrag, Eintritt, Durchführung, Abschluss, Verbleib. Jeder Punkt mit Datum, wer, Notiz und dem Dokument dazu. Fehlzeitenmeldungen sind mehrfach möglich. Die Punkte pflegt die Verwaltung (Verwaltung → Checkliste Teilnehmer).
- **Eintrittsmeldung** abgehakt → Stand „im Kurs“.
- **Abschluss erfassen** (abgeschlossen oder abgebrochen, letzter Kurstag) → Wiedervorlage zur **Verbleibserhebung sechs Monate später**, sichtbar in „Heute“ und im Kalender. Danach „Verbleib erfassen“.
- **Gesundheitsangaben** (Aufnahmeweg E, Nachteilsausgleich): eigener Bereich, nur Rolle Verwaltung, verschlüsselt, nicht im Protokoll.
- **Ausgabe als ZIP** mit PDF-Übersicht (Stammdaten, Förderweg, Checkliste, Dokumentliste, Verlauf) und allen Dokumenten im Original. So bleibt die Akte auch ohne das CRM lesbar (Lastenheft 7.2).

## 4 Dokumente

- Hochladen an drei Stellen: am **Vorgang** (z. B. Anfrage), im **Förderfall** (Gutschein, Bewilligung, Vertrag, Schriftverkehr) und in der **Akte** (Checkliste). Alles gehört zum selben Vorgang; die Akte zeigt auch die Unterlagen aus dem Förderfall.
- Beim Erledigen eines Förderweg-Schritts und beim Abhaken eines Checklisten-Punkts kann die Unterlage gleich mit hochgeladen werden. Die passende Dokumentart ist vorausgewählt.
- Erlaubt: PDF, JPG, PNG, Word, Excel, ODT, E-Mail, bis 20 MB.
- **Sicherheit:** verschlüsselt auf dem Server (AES-256 mit dem APP_KEY), Prüfsumme gegen Veränderung, jeder Abruf, jedes Hochladen und Löschen im Protokoll. Gesundheitsangaben nur Verwaltung und nur mit eingetragener Einwilligung. Löschen: Verwaltung, oder wer hochgeladen hat, am selben Tag.

## 5 Dubletten

Ziel: Niemand wird versehentlich zweimal angerufen, egal ob der Datensatz in der Akquise, im Förderfall, bei den Teilnehmern oder geschlossen ist.

**Vorgehen (übliche Praxis bei Adressabgleichen):**

1. **Vereinheitlichen:** Rechtsform weg (GmbH, UG, e.K. …), Umlaute, Groß- und Kleinschreibung, Telefon in einheitlicher Form (+49…), Website ohne „www“, Straße („Rheinstr. 12“ = „Rheinstraße 12“), allgemeine Zusätze wie „& Partner“ zählen beim Namen nicht.
2. **Vorauswahl** über Telefon, E-Mail, Domain, PLZ, Ort und Namensanfang, gegen **alle** Organisationen und Kontakte.
3. **Bewerten:**
   - **sicher**: gleiche Telefonnummer, gleiche E-Mail-Adresse, gleiche Website, gleicher Name mit gleicher PLZ, gleiche E-Mail wie ein Kontakt
   - **Verdacht**: gleicher Name am selben Ort, Tippfehler in längeren Namen am selben Ort, Name mit Zusatz bei gleicher PLZ („Müller Bau“ / „Müller Bau und Sanierung“), gleiche Anschrift, E-Mail-Domain passt zur Website
   - Freemail-Adressen (gmail, web.de …) und Plattformen (Facebook, Gelbe Seiten …) zählen nicht als gemeinsame Domain. Kurze Familiennamen mit einem Buchstaben Unterschied (Brunner / Brenner) gelten nicht als Verdacht.

**Was passiert:**

| Wo | sicher | Verdacht |
|---|---|---|
| **Import der Leadliste** | Zeile wird übersprungen, im Importprotokoll steht, mit welchem Eintrag und in welcher Phase („… wie Organisation 12 · Förderfall“) | Zeile wird angelegt, kommt in die **Dublettenprüfung** und erscheint **bis zur Entscheidung nicht in der Anrufliste** |
| **Anlegen von Hand** (Organisation, Kontakt, auch direkt im Vorgang) | Hinweis „Ähnliche Einträge im CRM“ schon beim Tippen | ebenso; wird trotzdem gespeichert, kommt der Eintrag in die Prüfung |

**Dublettenprüfung** (Stammdaten): Neu und Vorhanden nebeneinander, mit Grund. Entscheidung:

- **Derselbe: zusammenführen.** Der vorhandene Eintrag bleibt. Leere Felder werden ergänzt, Kontakte und Vorgänge mit Verlauf ziehen um, ein frisch importierter Vorgang ohne Verlauf entfällt.
- **Verschieden: freigeben.** Beide bleiben; dieses Paar wird nicht wieder gemeldet.
- **Bestand prüfen:** vergleicht den ganzen Bestand, z. B. einmalig nach der Einführung.

## 6 Aufbewahrung und Löschung

| Was | Frist |
|---|---|
| Interessent ohne Vertrag, Betrieb | 24 Monate nach letztem Kontakt, samt Dokumenten |
| Interessent ohne Vertrag, Privatperson | 6 Monate nach letztem Kontakt, samt Dokumenten |
| Teilnehmerakte samt Vorgang, Förderfall und Dokumenten | 10 Jahre ab Ende des Jahres, in dem die Maßnahme endete (A-22) |

Der tägliche Löschlauf löscht die Dateien mit. Akten ohne Kursende-Datum bleiben, bis das Datum eingetragen ist. Die Fristen hat Janosch am 04.10.2026 bestätigt.

**Löschersuchen (Art. 17 DSGVO):** Knopf „Löschersuchen (Art. 17)“ an Organisation, Kontakt und Vorgang, nur für die Verwaltung.

- Löscht sofort und endgültig: Vorgänge mit Aktivitäten, Terminen, Förderfällen und Dokumenten (auch die Dateien), Kontakte, Organisation, Prüfergebnisse, Dublettenverdacht, dazu die Protokolleinträge zu diesen Datensätzen. Im Importprotokoll wird der Firmenname unkenntlich gemacht.
- Ansprechperson eines Betriebs: Nur die Person wird gelöscht, die Vorgänge des Betriebs bleiben.
- Sperrliste (empfohlen, voreingestellt): Telefon, E-Mail bzw. Firmenname mit PLZ bleiben gesperrt, damit ein späterer Import niemanden erneut anlegt.
- Nicht möglich, solange eine Teilnehmerakte der Aufbewahrungspflicht unterliegt (Art. 17 Abs. 3 lit. b). Dann sperren statt löschen und begründet antworten (A-22, Abschnitt 3).
- Im Protokoll bleiben nur Zeitpunkt, wer, Anlass und Anzahl, ohne Namen.

## 7 Wer darf was

| | Verwaltung | Mitarbeitende (Vertrieb) |
|---|---|---|
| Vorgänge, Förderfälle, Dokumente am Vorgang und im Förderfall | ja | ja |
| Dublettenprüfung | ja | ja |
| Teilnehmerakten führen (Checkliste, Dokumente, Abschluss, Verbleib, ZIP) | ja | ja (Entscheidung 04.10.2026) |
| Gesundheitsangaben (Dokumente der Art „Gesundheit“, Nachteilsausgleich, Bereich in der Akte) | ja | nein |
| Dokumente löschen | ja | nur eigene, am selben Tag |

## 8 Offen

- **Vor dem ersten echten Teilnehmer** (Lastenheft 10, Nr. 3): A-11 (V02, V03, V05, V09), A-21, A-22, A-23, Blatt „Aufbau der Teilnehmerakte“, Handbuch Kapitel 9, Änderungsprotokoll, KVP-Liste A-15, Mitteilung an GüteZert. Claude formuliert, Janosch prüft.
- **Vom System erzeugte Dokumente** (Schulungsvertrag A-16, Informationsblatt A-26, Zertifikat A-09, Übergabeprotokoll): brauchen die Vorlagen als Datei.
- **Kennzahlen K1, K2, K7, K8, K10** aus A-04: brauchen die Definitionen aus A-04.
- Kursstatistik nach Prüfpunkt 7.2 in genau dem geforderten Format.
- Erklärtexte je Förderweg-Schritt und je Checklisten-Punkt (Janosch).
