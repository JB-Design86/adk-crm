# ADK CRM · Live-Schaltung von crm.adk-akademie.de

Stand 04.10.2026 · Ablauf vom Testbetrieb (`crm-test`) zum echten Betrieb (`crm.adk-akademie.de`) mit echten Daten und ohne Testdaten.

**Grundregel:** Claude richtet den Betrieb komplett ein, **solange noch keine echten Daten darin sind**. Ab dem ersten echten Datensatz arbeitet Claude nicht mehr in Plesk und nicht im Betrieb (Lastenheft 10, Nr. 8), sondern nur noch am Code über GitHub. Kennwörter und Schlüssel gibt immer Janosch selbst ein.

---

## Schritt 1 · Vorher entscheiden und klären (vor den ersten echten Daten)

| Nr. | Punkt | Wer | Stand |
|---|---|---|---|
| 1.1 | **Aufbewahrungsfristen**: Betriebe 24 Monate, Privatpersonen 6 Monate nach letztem Kontakt, Teilnehmerakte 10 Jahre nach Ende der Maßnahme | Janosch | bestätigt 04.10.2026 |
| 1.2 | **Datenschutzunterlagen** ergänzen: A-11 (CRM als eigene Verarbeitungstätigkeit, IONOS als Auftragsverarbeiter „Hosting CRM“), A-21 (Server, Zwei-Faktor, Verschlüsselung der Dokumente, Schnittstellen), A-22 (CRM-Zeile mit den Fristen aus 1.1) | Claude formuliert, Janosch prüft | Entwurf liegt vor: `DATENSCHUTZ_CRM_ENTWURF.md` |
| 1.3 | **Auftragsverarbeitungsvertrag mit IONOS**: liegt in der AVV-Ablage; gilt für alle Verträge unter der Kundennummer (Ziffer 12.1), also auch für den Server; ISO 27001 (Ziffer 9.2) | – | erledigt |
| 1.4 | **Standort des Servers**: Kundenbereich zeigt nur „Europa“, die AVV sichert EU bzw. EWR zu (Ziffer 4.3). Datenschutzrechtlich genügt das; in den Unterlagen steht „EU“. Den genauen Standort beim IONOS-Support erfragen und die Antwort in der AVV-Ablage ablegen (Lastenheft 11, Nr. 2). Im RIPE-Register heißt das Netz „fr-nbz-kw-ionos-cloud-nbz“, vermutlich Frankreich | Janosch fragt beim Support | offen, kein Hindernis für den Start |
| 1.5 | **Zielgruppe E (Arbeitsunfall)**: wird nicht angeboten, im CRM abgeschaltet | Janosch | entschieden 04.10.2026 |
| 1.6 | **Löschen auf Anfrage (Art. 17 DSGVO)**: Knopf „Löschersuchen (Art. 17)“ an Organisation, Kontakt und Vorgang, nur Verwaltung. Löscht sofort samt Dokumenten und Protokolleinträgen, auf Wunsch Sperrliste; Teilnehmerakten in der Aufbewahrungsfrist werden nicht gelöscht | Claude | erledigt 04.10.2026 |
| 1.7 | Offene Fragen aus `STUFE1_ABNAHME.md` Abschnitt 6. Entschieden: Protokoll 3 Jahre ab Jahresende, Löschen von Hand (Löschersuchen). Übrige („ruhen lassen“, Kennwort/Passwort …) können auch nach dem Start entschieden werden | Janosch | teilweise |
| 1.8 | **Vor der ersten echten Teilnehmerakte** (kann nach dem Start kommen): A-11 (V02, V03, V05, V09), A-21, A-22, A-23, Blatt „Aufbau der Teilnehmerakte“, Handbuch Kapitel 9, Änderungsprotokoll, KVP-Liste A-15, Mitteilung an GüteZert | Claude formuliert, Janosch prüft | offen |

## Schritt 2 · Betrieb einrichten (noch ohne echte Daten)

**Erledigt am 05.10.2026**, Einzelheiten in `BETRIEB.md` Abschnitt 12. Offen aus 2.10: in Plesk unter Backup-Einstellungen „Angegebenes Passwort“ für die Kennwörter in den Sicherungen setzen, damit eine Wiederherstellung auch auf einem neuen Server geht (Janosch; Kennwort in den Kennwortmanager), und die bei IONOS gebuchte Datensicherung prüfen. Der Inhalt der Plesk-Sicherungen ist nicht verschlüsselt; sie liegen nur für root lesbar auf dem Server, die Dokumente des CRM sind zusätzlich mit dem APP_KEY verschlüsselt.

Janosch meldet sich in Plesk an, Claude arbeitet im Browser. Dauer etwa eine Stunde.

| Nr. | Schritt | Wer |
|---|---|---|
| 2.1 | In Plesk die Domain `crm.adk-akademie.de` anlegen (eigene Domain wie bei crm-test, keine Subdomain), Systembenutzer mit Shell `/bin/bash`, PHP 8.4 (FPM), Dokumentstamm `httpdocs/public` | Claude |
| 2.2 | SSL-Zertifikat von Let's Encrypt, HTTP auf HTTPS umleiten | Claude |
| 2.3 | Datenbank `adk_crm` mit eigenem Benutzer anlegen. **Das Kennwort vergibt Janosch** | Claude legt an, Janosch vergibt das Kennwort |
| 2.4 | Git-Repository mit eigenem Deploy-Schlüssel verbinden (nur lesen), Bereitstellung **manuell** (kein automatisches Einspielen im Betrieb), Bereitstellungsaktionen wie in `BETRIEB.md` Abschnitt 4 | Claude, Janosch trägt den Schlüssel bei GitHub ein |
| 2.5 | `.env` für den Betrieb anlegen: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://crm.adk-akademie.de`, neuer `APP_KEY`, Datenbankzugang | Claude legt an, **Janosch trägt das Datenbank-Kennwort ein** |
| 2.6 | Erste Bereitstellung: Pakete, Migrationen, Cache. **Kein Seeder, keine Testdaten** | Claude |
| 2.7 | Geplante Aufgabe `/etc/cron.d/adk-crm` (Scheduler jede Minute: Löschlauf, später sipgate) | Claude |
| 2.8 | Plesk-Standardseite `index.html` in `public` entfernen, Header „X-Powered-By“ abschalten | Claude |
| 2.9 | Hochladen bis 20 MB prüfen (PHP-Grenzen, `BETRIEB.md` Abschnitt 11) | Claude |
| 2.10 | **Sicherung**: gebuchte Datensicherung aktiv, täglich mit Datenbank, `.env` und `storage/app/documents`, Aufbewahrung 30 Tage. **APP_KEY im Passwortmanager ablegen**: Ohne ihn sind Dokumente und Zwei-Faktor-Geheimnisse nicht mehr lesbar | Claude richtet ein, Janosch legt den Schlüssel ab |
| 2.11 | Prüfung: HTTPS mit gültigem Zertifikat, Sicherheits-Header, Anmeldeseite, `robots.txt`, Löschlauf als Probelauf (`adk:loeschlauf --dry-run`) | Claude |
| 2.12 | Einstellungen, die auf crm-test angepasst wurden (Prüfstufen, Förderweg-Schritte mit Erklärtexten, Checkliste), in den Betrieb übertragen: auf crm-test `php artisan adk:einstellungen export /tmp/adk-einstellungen.json`, im Betrieb `php artisan adk:einstellungen import /tmp/adk-einstellungen.json`, danach die Datei löschen. Übertragen werden nur Einstellungen, keine Testdaten; der Import geht nur in ein System ohne Förderfälle | Claude |

## Schritt 3 · Konten anlegen

Verwaltungskonto `j.baum@adk-akademie.de` angelegt am 05.10.2026. Weitere Konten im CRM unter Verwaltung → Benutzer.

| Nr. | Schritt | Wer |
|---|---|---|
| 3.1 | Erstes Verwaltungskonto per Konsole: `php artisan adk:benutzer-anlegen`. Kennwort eingeben, bei der ersten Anmeldung die Zwei-Faktor-App einrichten, **Wiederherstellungscodes sicher ablegen** | Janosch |
| 3.2 | Im CRM unter Verwaltung → Benutzer das Konto für den Vertrieb anlegen (Rolle Mitarbeitende). Die Person richtet bei der ersten Anmeldung selbst die Zwei-Faktor-App ein | Janosch |
| 3.3 | Kurz durchklicken: Anmeldung, Heute, Anrufliste (leer), Import-Seite | Janosch |

## Schritt 4 · Start mit echten Daten

Ab hier sind echte personenbezogene Daten im System. **Claude arbeitet ab jetzt nicht mehr in Plesk und nicht im Betrieb.**

| Nr. | Schritt | Wer |
|---|---|---|
| 4.1 | Erste echte Leadliste importieren (z. B. `ADK_CRM_Leadliste_001-100.xlsx`), Quelle und Abrufdatum angeben, Importprotokoll ansehen | Janosch |
| 4.2 | Stammdaten → Dublettenprüfung: Verdachtsfälle entscheiden | Janosch oder Vertrieb |
| 4.3 | Anrufliste: Vertrieb startet | Vertrieb |
| 4.4 | Erste Wochen: Rückmeldungen sammeln, Claude passt den Code an | alle |

## Schritt 5 · Danach: neue Funktionen und Korrekturen

1. Claude baut und testet lokal, pusht nach GitHub.
2. **crm-test** übernimmt den Stand automatisch. Janosch prüft dort mit Testdaten.
3. Passt alles, klickt Janosch in Plesk bei `crm.adk-akademie.de` auf **Git → Jetzt bereitstellen**. Datenbank-Änderungen laufen dabei automatisch mit.
4. Läuft etwas schief: in Plesk den vorherigen Commit bereitstellen und Claude den Fehler aus dem Protokoll zeigen (Bildschirmfoto ohne Kundendaten).

**crm-test bleibt** als Probebühne mit Testdaten bestehen. So kommt keine Änderung ungeprüft in den Betrieb. Wird es nicht mehr gebraucht, lässt es sich später in Plesk löschen.

## Später

- sipgate einbinden, sobald die Standortverifizierung durch ist (`BETRIEB.md` Abschnitt 10; AVV mit sipgate, A-11 und A-21 ergänzen).
- Claude Team mit AVV, falls Claude auch im Betrieb helfen soll (Lastenheft 10, Nr. 8).
