# ADK CRM · Ergänzungen für A-11, A-21 und A-22 (Entwurf)

Stand 04.10.2026 · Entwurf von Claude zum Einfügen in die Anlagen, Prüfung und Freigabe durch die Trägerleitung · Grundlage: Lastenheft v0.2 Abschnitt 10 Nr. 2, A-11 Version 1.3 vom 15.09.2026, A-21 Version 1.1, A-22 Version 1.0. Entscheidungen der Trägerleitung vom 04.10.2026: Fristen (Betriebe 24 Monate, Privatpersonen 6 Monate, Teilnehmerakte 10 Jahre), Protokoll 3 Jahre, Sicherungen 30 Tage, Aufnahmeweg E wird nicht angeboten.

Stellen mit **[prüfen: …]** sind wie in den Anlagen vor der Freigabe zu bestätigen. Die Ergänzungen beschreiben den Ist-Zustand des CRM. Teil D betrifft die Teilnehmerakte und muss erst vor der ersten echten Teilnehmerakte erledigt sein, nicht schon vor der Live-Schaltung.

Vorgeschlagene Versionen: A-11 1.4, A-21 1.2, A-22 1.1. Eintrag ins Änderungsprotokoll und in die KVP-Liste A-15.

---

## A · A-11 Verzeichnis der Verarbeitungstätigkeiten

### A.1 Neu: V18 · Akquise und Förderweg im ADK CRM

| Feld | Inhalt |
|---|---|
| Zweck | Gewinnung und Betreuung von Interessenten für die Maßnahmen der ADK: telefonische Ansprache von Betrieben aus öffentlich zugänglichen Quellen, Bearbeitung eingehender Anfragen, Wiedervorlagen und Termine, Begleitung fester Interessenten durch Antrag und Bewilligung (Förderweg) bis zur bestätigten Einschreibung, Auswertung der Akquise (Quoten je Branche, Kanal und Quelle) |
| Betroffene | Inhaber und Ansprechpersonen von Betrieben; Interessenten (Privatpersonen) mit eigener Anfrage oder Einwilligung; feste Interessenten im Förderweg; Ansprechpersonen bei Kostenträgern |
| Datenkategorien | Betrieb: Name, Rechtsform, Branche, Anschrift, Telefon, E-Mail, Website, Fundstelle, Mitarbeiterzahl, Ergebnisse der Prüfstufen. Ansprechperson: Name, Funktion, dienstliche Kontaktdaten. Privatperson: Name, Kontaktdaten, Zielgruppe bzw. Kostenträger, Einwilligung in die Telefonansprache mit Datum und Nachweis, Datum des übermittelten Datenschutzhinweises. Verlauf: Anrufe mit Ergebnis, Notizen, Termine, Wiedervorlagen, Telefonate. Förderweg: Kundennummer beim Kostenträger, Gutscheinnummer und Gültigkeit, Ansprechperson beim Kostenträger, erledigte Schritte mit Datum, Dokumente (Bildungsgutschein, Bewilligung bzw. Kostenzusage, Vertrag, Schriftverkehr). Sperrliste: Telefon, E-Mail, Firmenname mit PLZ, Grund. Protokoll: wer wann was getan hat |
| Herkunft | Betriebe: öffentlich zugängliche Quellen (Impressum, Branchenverzeichnisse); je Datensatz sind Quelle und Abrufdatum Pflicht. Privatpersonen: die betroffene Person selbst (Website-Formular, E-Mail, Telefon, Empfehlung). Kostenträger bei Zuweisung |
| Rechtsgrundlage | Betriebe und Ansprechpersonen: **f** (Direktwerbung gegenüber Unternehmen, Erwägungsgrund 47 DSGVO); telefonische Ansprache nur bei mutmaßlicher Einwilligung nach § 7 Abs. 2 Nr. 1 UWG, geprüft über die Prüfstufen der Branchenmatrix. Privatpersonen: **b** bei eigener Anfrage; Telefonansprache ohne Anfrage nur mit Einwilligung (**a**, § 7 Abs. 2 Nr. 1 UWG), Nachweis im CRM. Förderweg: **b**. Sperrliste: **f** und **c** (Beachtung von Werbewiderspruch und Löschersuchen, Art. 17, Art. 21 Abs. 3). Protokoll: **f** und Art. 32. Gesundheitsdaten (Art. 9) werden in Akquise und Förderweg nicht verarbeitet; der Aufnahmeweg E (Arbeitsunfall) wird nicht angeboten und ist im CRM abgeschaltet (Entscheidung der Trägerleitung vom 04.10.2026) |
| Systeme, Empfänger | ADK CRM unter crm.adk-akademie.de, eigene Anwendung auf einem virtuellen Server der IONOS SE in einem Rechenzentrum in der EU (IONOS-Kundenbereich: Rechenzentrum „Europa“; AVV Ziffer 4.3: Verarbeitung in der EU bzw. im EWR) **[prüfen: genauen Standort beim IONOS-Support erfragen und die Antwort in der AVV-Ablage ablegen]**, eigene Datenbank nur für das CRM. Zugriff: Trägerleitung (Rolle Verwaltung) und Vertrieb (Rolle Mitarbeitende), Gesundheitsangaben nur Verwaltung. Export nur für angemeldete Personen als Datei auf das eigene Gerät, jeder Export im Protokoll. Empfänger: Kostenträger im Förderweg (V04). Der Programmcode liegt bei GitHub und enthält keine personenbezogenen Daten. Testumgebung crm-test.adk-akademie.de nur mit erfundenen Daten |
| Drittland | Keine (Verarbeitung in der EU). Der KI-Assistent Claude entwickelt den Programmcode und arbeitet ausschließlich mit erfundenen Testdaten in der Testumgebung, nicht im Betrieb und nicht mit personenbezogenen Daten (A-21, 2.4) |
| Löschung | Automatisch durch den täglichen Löschlauf des CRM: Betriebe ohne Vertrag 24 Monate nach letztem Kontakt, Privatpersonen ohne Vertrag 6 Monate nach letztem Kontakt, Nachweis der Einwilligung in die Telefonansprache 5 Jahre nach Erteilung bzw. letzter Verwendung, Importprotokoll 3 Jahre ab Jahresende, Sperrliste unbefristet. Mit Vertrag: Fristen der Teilnehmerakte (V03). Löschersuchen sofort über die Löschfunktion des CRM (V15). Einzelheiten A-22 |

### A.2 Änderung V02 · Interessenten- und Beratungsverwaltung

- **Systeme, Empfänger** ergänzen: „Interessentenvorgänge, Termine und Wiedervorlagen im ADK CRM (V18).“
- **Herkunft** ergänzen: „Anfragen über das Kontaktformular und bestätigte Kursheft-Anforderungen der Website werden automatisch als Vorgang ins ADK CRM übernommen (signierte Übertragung auf demselben Server). Die Website löscht ihre Kopie sieben Tage nach der Übergabe.“
- **Kursheft** (V01 bzw. Website): Name, E-Mail, Finanzierungsweg, freiwillig Telefon. Einwilligung in Zusendung und Beratung per E-Mail (Double-Opt-in), Anruf nur mit eigenem Häkchen. Rechtsgrundlage Art. 6 Abs. 1 Buchst. a DSGVO, § 7 UWG. **[prüfen: Kopplung Kursheft und E-Mail-Beratung mit der Datenschutz-Beratung abstimmen]**
- **Löschung** ersetzen durch: „Privatpersonen 6 Monate nach letztem Kontakt, wenn kein Vertrag zustande kommt. Betriebe und deren Ansprechpersonen 24 Monate nach letztem Kontakt, weil die Wiedervorlage ‚Später Interesse‘ und die Förderwege von Betrieben (§ 82 SGB III) länger als ein halbes Jahr laufen (Entscheidung der Trägerleitung vom 04.10.2026, Handbuch 9.6).“

### A.3 Änderung V15 · Betroffenenrechte und Datenschutzvorfälle

- **Systeme, Empfänger** ergänzen: „Löschersuchen zu Daten im ADK CRM werden über die Funktion ‚Löschersuchen (Art. 17)‘ ausgeführt. Sie löscht sofort und endgültig, auf Wunsch kommen Telefon, E-Mail bzw. Firmenname mit PLZ auf die Sperrliste. Das CRM-Protokoll weist Zeitpunkt, Anlass und Umfang ohne Namen nach. Daten mit Aufbewahrungspflicht (Teilnehmerakte) werden nicht gelöscht, sondern gesperrt; die Person erhält eine begründete Antwort.“

### A.4 Änderung Abschnitt 3 · Auftragsverarbeiter

Zeile **IONOS SE** ersetzen:

| Anbieter | Leistung | Verarbeitungsort | Vertrag | Status |
|---|---|---|---|---|
| IONOS SE | DNS-Verwaltung der Domain; virtueller Server für das ADK CRM (Betrieb und Testumgebung) und seit 06.10.2026 für die Website adk-akademie.de mit Kontaktformular und Kursheft-Anforderung, mit Datensicherung; bis 09/2026 E-Mail-Hosting | EU bzw. EWR (AVV Ziffer 4.3; Kundenbereich: „Europa“) | AVV nach Art. 28, Version 1.2 vom 06/2023, bestätigt; gilt für alle Verträge unter der Kundennummer (Ziffer 12.1), damit auch für den Server; Nachweis ISO 27001 (Ziffer 9.2) | vorhanden |

Zeile **df.eu (domainfactory)**, falls dort als Hoster der Website geführt: Website-Hosting bis 06.10.2026, danach IONOS. Nach der Kündigung Status „beendet“ und die Löschung der Daten durch df.eu bestätigen lassen.

Satz „Kein Auftragsverarbeiter“ ergänzen um: „GitHub (Ablage des Programmcodes des CRM, keine personenbezogenen Daten), Let’s Encrypt (Ausstellung der Serverzertifikate, keine personenbezogenen Daten).“

### A.5 Änderung Abschnitt 4 · Systemlandschaft

Neue Zeile:

| System | Nutzung | Zuständig, Zugriff |
|---|---|---|
| Website adk-akademie.de | Auftritt, Kontaktformular, Kursheft mit Double-Opt-in; Formulardaten in einer Datei-Datenbank außerhalb des öffentlichen Bereichs, täglicher Löschlauf (14 Tage, 6 Monate, 7 Tage), Server-Protokolle 30 Tage. Auf dem Server des CRM, aber getrennter Systembenutzer | Trägerleitung (Plesk) |
| ADK CRM crm.adk-akademie.de | Akquise, Förderweg, Dokumente, Sperrliste; ab der ersten Teilnehmerakte auch Teilnehmerverwaltung (Teil D). Testumgebung crm-test.adk-akademie.de nur mit erfundenen Daten | Konto je Person, Zwei-Faktor-Anmeldung Pflicht, Rollen Verwaltung (Trägerleitung) und Mitarbeitende (Vertrieb); Server-Verwaltung (Plesk) nur Trägerleitung |

---

## B · A-21 Technische und organisatorische Maßnahmen

Vorschlag: neuer Abschnitt **8 · ADK CRM**, dazu Zeilen in der Übersicht der Prüfpunkte (B.10).

### B.1 Zutrittskontrolle

- Server in einem Rechenzentrum der IONOS SE in der EU, Zutritt nach den technischen und organisatorischen Maßnahmen des Anbieters. IONOS ist nach ISO 27001 zertifiziert (AVV Ziffer 9.2, Zertifikat auf der Website des Anbieters).

### B.2 Zugangskontrolle

- Eigenes Konto je Person, keine geteilten Konten. Kennwort mindestens zehn Zeichen, gespeichert nur als bcrypt-Hash.
- **Zwei-Faktor-Anmeldung ist für alle Konten Pflicht** (Authenticator-App, Wiederherstellungscodes). Ohne eingerichtete Zwei-Faktor-Anmeldung kein Zugang.
- Höchstens fünf Anmeldeversuche je Minute, Fehlversuche im Protokoll. Abmeldung nach 120 Minuten Inaktivität.
- Konten lassen sich ohne Datenverlust sperren; ein gesperrtes Konto wird sofort abgemeldet. Konten legt nur die Verwaltung an.
- Server-Verwaltung (Plesk, SSH) nur durch die Trägerleitung **[prüfen: Zwei-Faktor-Anmeldung für Plesk aktiv; SSH nur mit Schlüssel]**. Fail2Ban sperrt wiederholte Fehlanmeldungen am Server.
- Keine öffentliche Registrierung, alle Seiten nur nach Anmeldung, Suchmaschinen ausgesperrt.

### B.3 Zugriffskontrolle

- Zwei Rollen. **Verwaltung**: alle Rechte. **Mitarbeitende** (Vertrieb): Vorgänge, Anrufliste, Förderfälle, Teilnehmerakten, Dokumente, Dublettenprüfung, Auswertung; nicht: Gesundheitsangaben, Import, Export, Benutzer, Sperrliste bearbeiten, Einstellungen, Protokoll, Löschersuchen.
- **Gesundheitsangaben** (Dokumentart „Gesundheit“, Nachteilsausgleich, Einwilligung) sind nur für die Verwaltung sichtbar. Ablage nur mit eingetragener Einwilligung.
- Dokumente sind nie direkt über das Internet erreichbar, sondern nur über das CRM nach Rechteprüfung.

### B.4 Trennung

- Eigene Datenbank nur für das CRM, eigene Domain und eigener Systembenutzer je Umgebung.
- Testumgebung crm-test.adk-akademie.de mit eigener Datenbank und ausschließlich erfundenen Daten.

### B.5 Verschlüsselung

- Zugriff nur über HTTPS mit HSTS (Zertifikat von Let’s Encrypt, automatische Verlängerung), HTTP wird umgeleitet. Cookies nur über HTTPS, Sitzungsinhalt verschlüsselt.
- **Dokumente** (Bildungsgutschein, Bewilligung, Vertrag, Bescheinigungen) liegen mit AES-256 verschlüsselt auf dem Server, mit Prüfsumme gegen unbemerkte Veränderung.
- Zwei-Faktor-Geheimnisse, Zugangsschlüssel zu angebundenen Diensten und der Nachteilsausgleich sind in der Datenbank verschlüsselt.
- Der Anwendungsschlüssel (APP_KEY) liegt auf dem Server und zusätzlich im Kennwortmanager der Trägerleitung. Ohne ihn sind die Dokumente nicht lesbar.

### B.6 Weitergabekontrolle

- In der jetzigen Ausbaustufe keine Schnittstellen nach außen. Vor jeder neuen Schnittstelle (Website-Formular, Telefonie sipgate) werden A-11 und diese Anlage ergänzt.
- Export nur für angemeldete Personen mit Recht, als Datei auf das eigene Gerät, jeder Export im Protokoll.
- Die Sperrliste greift beim Import und in der Anrufliste.

### B.7 Eingabekontrolle

- Das CRM protokolliert mit Person und Zeitpunkt: Anmeldungen und Fehlversuche, jede Aktivität, jeden Statuswechsel, Änderungen an Vorgängen, Organisationen, Kontakten, Förderfällen und Teilnehmerakten, jeden Import und Export, jedes Hochladen, Abrufen und Löschen eines Dokuments, Löschläufe und Löschersuchen.
- Protokolleinträge lassen sich in der Oberfläche weder ändern noch löschen. Sie entfallen nur mit dem gelöschten Datensatz.

### B.8 Verfügbarkeit und Belastbarkeit

- Tägliche Sicherung über die gebuchte Datensicherung (IONOS-Backup bzw. Plesk-Sicherungsverwaltung): Datenbank, Dokumente, Konfiguration. Aufbewahrung 30 Tage, danach rollierend überschrieben.
- Wiederherstellung einmal im Jahr erproben.
- Der Programmcode liegt versioniert bei GitHub und lässt sich jederzeit neu bereitstellen.

### B.9 Aktualität der Systeme und Änderungen

- Server: Ubuntu 24.04 LTS mit Sicherheitsupdates, Plesk mit automatischen Updates **[prüfen: automatische Updates in Plesk aktiv]**.
- Änderungen am CRM nur über GitHub, mit automatischen Tests, zuerst in der Testumgebung, erst nach Prüfung durch die Trägerleitung im Betrieb.
- **KI-Werkzeuge (ergänzt 2.4):** Der KI-Assistent Claude entwickelt das CRM. Er arbeitet nur am Programmcode und in der Testumgebung mit erfundenen Daten. Im Betrieb mit echten Daten arbeitet er nicht, solange kein Vertrag zur Auftragsverarbeitung mit dem Anbieter besteht. Kennwörter und Schlüssel gibt nur die Trägerleitung ein.

### B.10 Neue Prüfpunkte (Übersicht Abschnitt 7)

| Maßnahme | Nachweis | Prüfrhythmus |
|---|---|---|
| CRM: Zwei-Faktor-Anmeldung für alle Konten, keine verwaisten Konten | Benutzerliste im CRM | internes Audit |
| CRM: Sicherung und Wiederherstellung | Plesk-Sicherungsverwaltung, Wiederherstellungstest | jährlich |
| CRM: Löschlauf läuft | CRM-Protokoll, Bereich Löschlauf | jährlich zum Löschtermin |
| Server: Updates und Zertifikat | Plesk-Übersicht | halbjährlich |
| APP_KEY im Kennwortmanager | Eintrag vorhanden | internes Audit |

---

## C · A-22 Löschkonzept und Löschprotokoll

### C.1 Neue Zeilen in Abschnitt 2 · Fristen

| Daten | Frist | Beginn der Frist | Wo gelöscht wird |
|---|---|---|---|
| CRM: Interessent Betrieb ohne Vertrag (Vorgang, Ansprechpersonen, Verlauf, Termine, Dokumente) | 24 Monate | letzter Kontakt | ADK CRM, täglicher Löschlauf (automatisch) |
| CRM: Interessent Privatperson ohne Vertrag | 6 Monate | letzter Kontakt | ADK CRM, täglicher Löschlauf (automatisch) |
| CRM: Nachweis Einwilligung Telefonansprache | 5 Jahre | Erteilung bzw. letzte Verwendung | ADK CRM, täglicher Löschlauf (automatisch) |
| CRM: Importprotokoll (Datei, Quelle, übersprungene Zeilen) | 3 Jahre | Jahresende | ADK CRM, täglicher Löschlauf (automatisch) |
| CRM: Sperrliste (Werbewiderspruch, Löschersuchen) | unbefristet | – | bleibt als Nachweis, dass nicht mehr angesprochen wird; nur Telefon, E-Mail, Firmenname mit PLZ, Grund |
| CRM: Protokoll (Anmeldungen, Änderungen, Exporte, Importe, Dokumentabrufe) | 3 Jahre | Jahresende | ADK CRM, täglicher Löschlauf (automatisch); Einträge zu gelöschten Datensätzen entfallen schon mit dem Datensatz |
| CRM: Datensicherungen | 30 Tage | Erstellung | gebuchte Datensicherung (IONOS bzw. Plesk), rollierend überschrieben |

Zeile **Interessentendaten ohne Vertragsschluss** ergänzen um „außerhalb des CRM“.

### C.2 Ergänzung Abschnitt 3 · Löschtermine und Verfahren

- **ADK CRM:** Der Löschlauf läuft täglich um 02:30 Uhr und löscht alle abgelaufenen Datensätze automatisch und endgültig, samt Dateien und Protokolleinträgen. Anzahl und Zeitpunkt stehen im CRM-Protokoll (Bereich „Löschlauf“). Für das CRM ersetzt dieser Eintrag die einzelne Zeile im Löschprotokoll. Beim Jahreslöschlauf überträgt die Trägerleitung die Summen des Vorjahres in das Löschprotokoll (Abschnitt 4).
- **Löschersuchen im CRM:** über „Löschersuchen (Art. 17)“ an Organisation, Kontakt oder Vorgang, sofort und endgültig, auf Wunsch mit Sperrliste. Eine Teilnehmerakte in der Aufbewahrungsfrist verhindert die Löschung; dann sperren statt löschen und begründet antworten.
- Gelöschte Daten können bis zum Ablauf der Datensicherungen noch in Sicherungen enthalten sein. Sie werden nicht zurückgespielt, außer zur Wiederherstellung nach einem Ausfall; danach wird der Löschlauf erneut ausgeführt.

---

## D · Erst vor der ersten echten Teilnehmerakte (Lastenheft 10 Nr. 3)

Die Teilnehmerakte zieht vom SharePoint-Ordner „Teilnehmende“ ins CRM (Entscheidung vom 24.09.2026). Vor der ersten echten Akte anpassen:

| Unterlage | Änderung |
|---|---|
| A-11 V03 | Systeme: „Teilnehmerakte im ADK CRM (Stammdaten, Checkliste Vertrag bis Verbleib, Dokumente verschlüsselt)“ statt SharePoint-Ordner. Löschung: 10 Jahre ab Ende des Jahres, in dem die Maßnahme endete, automatisch |
| A-11 V04 | Ablage des Schriftverkehrs mit dem Kostenträger als Dokument in der Akte |
| A-11 V05 | Bewertungsbögen A-08 und Protokolle der Zwischengespräche als PDF in der Akte; Anwesenheit weiter nach A-07 **[prüfen]** |
| A-11 V09 | Verbleibsstatus in der Akte, automatische Wiedervorlage sechs Monate nach Kursende |
| A-11 Abschnitt 4 | Zeile „SharePoint-Ordner Teilnehmende“ anpassen bzw. streichen |
| A-21 2.3 und 2.4 | Teilnehmerdaten im CRM statt ausschließlich im SharePoint-Ordner; die technische Absicherung gegenüber KI-Werkzeugen ergibt sich aus B.9 |
| A-22 Abschnitt 2 | Zeile Teilnehmerakte: „ADK CRM, automatisch“ statt „Ordner Teilnehmende“; Papierablage bleibt |
| A-23 Datenschutzhinweis | Speicherort ADK CRM, IONOS als Auftragsverarbeiter, Rechte der Teilnehmenden unverändert **[prüfen]** |
| Weitere | Blatt „Aufbau der Teilnehmerakte“, Handbuch Kapitel 9, Änderungsprotokoll, KVP-Liste A-15, Mitteilung an GüteZert per E-Mail |
