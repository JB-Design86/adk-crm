# ADK CRM · Betrieb auf Plesk

Stand 25.09.2026 · Stufe 1 · Server: IONOS VPS L+, Ubuntu 24.04 LTS, Plesk

Diese Anleitung beschreibt die Einrichtung von `crm.adk-akademie.de` (Betrieb) und `crm-test.adk-akademie.de` (Test mit erfundenen Daten). Beide Umgebungen laufen aus demselben GitHub-Repository, jede mit eigener Datenbank und eigener `.env`.

Pfade wie `/var/www/vhosts/crm.adk-akademie.de/httpdocs` und `/opt/plesk/php/8.4/bin/php` sind die Plesk-Standards. Weichen sie auf dem Server ab, bitte anpassen.

Menünamen beziehen sich auf die deutsche Plesk-Oberfläche (Plesk Obsidian 18.0).

---

## 1 Umgebungen

| | Betrieb | Test |
|---|---|---|
| Adresse | `https://crm.adk-akademie.de` | `https://crm-test.adk-akademie.de` |
| `APP_ENV` | `production` | `staging` |
| `APP_DEBUG` | `false` | `false` |
| `APP_FORCE_HTTPS` | `true` | `true` |
| Datenbank | eigene MariaDB-Datenbank, z. B. `adk_crm` | eigene MariaDB-Datenbank, z. B. `adk_crm_test` |
| Testdaten | nie (der Seeder bricht bei `APP_ENV=production` ab) | ja, `php artisan migrate:fresh --seed` |
| Git-Zweig | `main` | `main` (oder ein eigener Zweig für Vorabversionen) |

---

## 2 Plesk-Einstellungen je Umgebung

### 2.1 Hosting

Betrieb und Test werden als **zwei eigene Domains** angelegt, nicht als Subdomains von `adk-akademie.de`. So bekommt jede Umgebung einen eigenen Systembenutzer und eigene Dateirechte, und Plesk hält sich nicht für die Hauptdomain zuständig. Sonst würde Plesk E-Mails an `@adk-akademie.de` (z. B. eigene Benachrichtigungen) lokal zustellen statt an Exchange Online.

1. **Websites & Domains → Domain hinzufügen → Leere Website:** Domainname `crm.adk-akademie.de` bzw. `crm-test.adk-akademie.de`. Benutzername und Kennwort des Systembenutzers vergeben (Passwortmanager).
2. **Mail-Einstellungen** der Domain: E-Mail-Dienst **deaktivieren**. Das CRM empfängt keine E-Mails.
3. **Hosting & DNS → Hosting-Einstellungen:**
   - Dokumentenstamm: **`httpdocs/public`** (das Repository wird nach `httpdocs` ausgeliefert; nur `public/` ist vom Web aus erreichbar).
   - „Permanente SEO-sichere 301-Weiterleitung von HTTP zu HTTPS“: **an**. Die Anwendung leitet zusätzlich selbst um und setzt HSTS.
   - Plesk legt beim Anlegen der Domain eine Standardseite `index.html` an, auch im neuen Dokumentenstamm `httpdocs/public`. Diese Datei nach der ersten Bereitstellung **löschen**, sonst zeigt die Startseite die Plesk-Seite statt des CRM.
4. **SSL/TLS-Zertifikate:** Let's Encrypt für die Domain ausstellen (erst wenn der DNS-Eintrag auf den Server zeigt), automatische Verlängerung an.
5. **PHP-Einstellungen:**
   - PHP-Version: **8.4** (8.3 geht auch), Ausführung als **FPM-Anwendung von nginx bzw. Apache**.
   - `memory_limit = 256M`
   - `upload_max_filesize = 20M`, `post_max_size = 25M` (Import bis 20 MB)
   - `max_execution_time = 120` (größere Importe)
   - `expose_php = Off`
   - `open_basedir`: Plesk-Standard `{WEBSPACEROOT}{/}{:}{TMP}{/}` genügt.

### 2.2 Benötigte PHP-Erweiterungen

Laut `composer check-platform-reqs --no-dev`:

`ctype`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `intl`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `session`, `tokenizer`, `xmlreader`, `zip`

Zusätzlich für die Datenbank: `pdo_mysql` (MariaDB). In Plesk unter **Tools & Einstellungen → PHP-Einstellungen → 8.4 → Erweiterungen** prüfen. `intl` und `zip` fehlen auf manchen Installationen und müssen aktiviert werden.

Node.js wird auf dem Server **nicht** benötigt: Die gebauten Assets (`public/build`, `public/css`, `public/js`, `public/fonts`) liegen im Repository.

### 2.3 Datenbank

1. **Websites & Domains → Datenbanken → Datenbank hinzufügen:** Typ MariaDB, Name z. B. `adk_crm` bzw. `adk_crm_test`.
2. Eigener Datenbankbenutzer nur für diese Datenbank (Lastenheft Abschnitt 9: eigene Datenbank nur für dieses System).
3. Zeichensatz `utf8mb4`, Sortierung `utf8mb4_unicode_ci` (Laravel-Standard).
4. Zugang nur von `localhost`.

---

## 3 Git-Anbindung

Das Repository `JB-Design86/adk-crm` ist privat. Plesk ruft es per SSH mit einem Deploy-Schlüssel ab.

1. **Websites & Domains → Git → Repository hinzufügen**
   - Remote-Repository: `git@github.com:JB-Design86/adk-crm.git`
   - Plesk zeigt einen öffentlichen SSH-Schlüssel an. Diesen in GitHub unter **Repository → Settings → Deploy keys → Add deploy key** eintragen, **ohne** Schreibrecht.
   - Zweig: `main`
   - Bereitstellungsmodus: **Manuell** (empfohlen für den Betrieb) oder Automatisch (für den Test).
   - Zielverzeichnis: `/httpdocs`
2. **Zusätzliche Bereitstellungsaktionen aktivieren** und das Deploy-Skript aus Abschnitt 4 eintragen.

**crm-test aktualisiert sich automatisch:** Bereitstellungsmodus „auto“, und in GitHub unter **Settings → Webhooks** ruft ein Webhook bei jedem Push die Plesk-Adresse `https://vigorous-bhabha.217-160-106-171.plesk.page:8443/modules/git/public/web-hook.php?uuid=…` auf (nur Push-Ereignis, JSON, SSL-Prüfung an). Jeder Push nach `main` landet damit wenige Sekunden später auf crm-test, inklusive Migrationen. Der Betrieb `crm` bleibt bei **manuell**: Dort wird erst nach Prüfung auf crm-test bereitgestellt.

---

## 4 Deploy-Skript

In Plesk unter **Git → Repository-Einstellungen → Zusätzliche Bereitstellungsaktionen**. Plesk führt die Befehle im Zielverzeichnis aus.

```bash
# PHP und Composer von Plesk
PHP=/opt/plesk/php/8.4/bin/php
COMPOSER=/usr/lib/plesk-9.0/composer.phar

$PHP $COMPOSER install --no-dev --optimize-autoloader --no-interaction --no-progress
$PHP artisan migrate --force
$PHP artisan optimize
$PHP artisan filament:optimize
[ -L public/storage ] || $PHP artisan storage:link
```

Auf `crm-test` sind die Aktionen per Befehl gesetzt (eine Zeile, `&&`-verknüpft):

```bash
plesk ext git --update -domain crm-test.adk-akademie.de -name adk-crm -run-actions true \
  -actions '/opt/plesk/php/8.4/bin/php /usr/lib/plesk-9.0/composer.phar install --no-dev --optimize-autoloader --no-interaction --no-progress && /opt/plesk/php/8.4/bin/php artisan migrate --force && /opt/plesk/php/8.4/bin/php artisan optimize && /opt/plesk/php/8.4/bin/php artisan filament:optimize'
```

Bereitstellen von Hand: `plesk ext git --fetch …` und danach `plesk ext git --deploy -domain crm-test.adk-akademie.de -name adk-crm`, oder in Plesk unter **Git → Jetzt bereitstellen**.

Hinweise:

- **Wichtig:** Plesk führt die Bereitstellungsaktionen über die Shell des Systembenutzers aus. Steht dort `/bin/false` (Standard beim Anlegen), laufen die Aktionen stillschweigend **nicht**. Shell setzen mit `plesk bin subscription --update crm-test.adk-akademie.de -shell /bin/bash`.
- Faker ist ein reguläres Paket (nicht nur für Entwicklung), damit `db:seed` auf `crm-test` mit `--no-dev` funktioniert. In der Betriebsumgebung bricht der Seeder ab.
- Liegt `composer.phar` woanders, zeigt `plesk bin extension --list` bzw. die Composer-Erweiterung in Plesk den Pfad. Alternativ `composer` über die Plesk-Composer-Erweiterung ausführen.
- `php artisan optimize` legt Konfiguration, Routen und Views im Cache ab. **Nach jeder Änderung an der `.env`** den Befehl erneut ausführen (oder die Bereitstellung wiederholen).
- `storage:link` verknüpft `public/storage` mit `storage/app/public`. Das CRM legt dort in Stufe 1 keine Dateien ab. Hochgeladene Importdateien liegen in `storage/app/private` außerhalb des Webverzeichnisses und werden nach dem Import gelöscht.

---

## 5 Geplante Aufgabe

Der Laravel-Scheduler muss jede Minute laufen, als Systembenutzer der Domain. Auf `crm-test` ist das als Datei `/etc/cron.d/adk-crm-test` eingerichtet (von Plesk unabhängig, wird bei Plesk-Updates nicht überschrieben):

```cron
SHELL=/bin/sh
* * * * * crmtest /opt/plesk/php/8.4/bin/php /var/www/vhosts/crm-test.adk-akademie.de/httpdocs/artisan schedule:run >/dev/null 2>&1
```

Für den Betrieb entsprechend `/etc/cron.d/adk-crm` mit dem Systembenutzer von `crm.adk-akademie.de`. Alternativ in Plesk unter **Websites & Domains → Geplante Aufgaben → Aufgabe hinzufügen** (Befehl wie oben ohne Benutzername, jede Minute).

Prüfen: `sudo -u crmtest /opt/plesk/php/8.4/bin/php artisan schedule:list` im Anwendungsverzeichnis.

Der Scheduler startet alle 5 Minuten den sipgate-Abgleich `adk:sipgate-abgleich` (nur wenn sipgate eingerichtet ist, siehe Abschnitt 10) und täglich um 02:30 Uhr den Löschlauf `adk:loeschlauf` (Uhrzeit in `config/adk.php`, `retention.run_at`). Die Ausgabe landet in `storage/logs/loeschlauf.log`, Anzahl und Zeitpunkt zusätzlich im Protokoll der Anwendung.

Probelauf von Hand:

```bash
/opt/plesk/php/8.4/bin/php artisan adk:loeschlauf --dry-run
```

---

## 6 Ersteinrichtung

Einmalig je Umgebung, per SSH im Verzeichnis `httpdocs` (oder über die Plesk-Konsole):

1. `.env` aus der Vorlage anlegen und ausfüllen:
   ```bash
   cp .env.example .env
   ```
   - `APP_ENV`, `APP_URL`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` eintragen.
   - Schlüssel erzeugen und in `APP_KEY` eintragen:
     ```bash
     /opt/plesk/php/8.4/bin/php artisan key:generate
     ```
   - Rechte: `chmod 600 .env`
2. Bereitstellung in Plesk auslösen (führt das Deploy-Skript aus, legt die Tabellen an).
3. **Erstes Verwaltungskonto** anlegen:
   ```bash
   /opt/plesk/php/8.4/bin/php artisan adk:benutzer-anlegen --role=admin
   ```
   Der Befehl fragt Name, E-Mail und Kennwort (mindestens 10 Zeichen) ab. Die Zwei-Faktor-Anmeldung richtet die Person bei der ersten Anmeldung selbst ein: QR-Code mit der Authenticator-App scannen, Code bestätigen, **Wiederherstellungscodes sicher ablegen** (Passwortmanager).
4. Weitere Konten legt die Verwaltung in der Oberfläche unter **Verwaltung → Benutzer** an.
5. Schreibrechte: `storage/` und `bootstrap/cache/` müssen für den PHP-Benutzer des Abonnements beschreibbar sein (Plesk-Standard).

### 6.1 Nur Testumgebung: Testdaten

```bash
/opt/plesk/php/8.4/bin/php artisan migrate:fresh --seed --force
```

Löscht alle Daten der Testdatenbank und spielt die erfundenen Testdaten neu ein. Der Seeder bricht in `APP_ENV=production` ab.

**Testkonten (nur `crm-test.adk-akademie.de`):**

| Rolle | E-Mail | Kennwort |
|---|---|---|
| Verwaltung | `verwaltung@crm-test.example` | `Test-Verwaltung-2026` |
| Mitarbeitende | `mitarbeit@crm-test.example` | `Test-Mitarbeit-2026` |

Bei der ersten Anmeldung verlangt das System die Einrichtung der Zwei-Faktor-Anmeldung. Diese Zugangsdaten stehen im Repository und dürfen nie in der Betriebsumgebung verwendet werden.

---

## 7 Sicherung

Laut Lastenheft über das IONOS-Backup, getrennt vom Server, täglich. Wiederherstellung einmal im Jahr erproben.

| Was | Pfad | Warum |
|---|---|---|
| **Datenbank** | MariaDB `adk_crm` | alle Daten. Zusätzlich nächtlicher Dump über **Plesk → Sicherungsverwaltung** (Datenbanken einschließen) |
| **`.env`** | `httpdocs/.env` | `APP_KEY` wird zum Entschlüsseln gebraucht (Sitzungen, Zwei-Faktor-Geheimnisse). **Ohne den alten `APP_KEY` müssen alle Konten die Zwei-Faktor-Anmeldung neu einrichten, und die Dokumente sind nicht mehr lesbar.** Zusätzlich im Passwortmanager ablegen |
| **`storage/app/documents`** | `httpdocs/storage/app/documents` | **alle Dokumente** (Vorgang, Förderfall, Teilnehmerakte), verschlüsselt. Nur mit dem passenden `APP_KEY` lesbar |
| `storage/app/private/email-templates` | `httpdocs/storage/app/private/email-templates` | Anhänge der E-Mail-Vorlagen (z. B. Kursheft), keine personenbezogenen Daten. Fehlt die Datei, sendet „E-Mail schreiben“ mit dieser Vorlage nicht, bis sie neu hochgeladen ist |
| `storage/logs` | `httpdocs/storage/logs` | Fehler- und Löschlaufprotokolle (optional) |

Nicht gesichert werden müssen `vendor/` und der Code: Beides stellt die Bereitstellung aus GitHub wieder her.

**Wiederherstellung:** Repository bereitstellen, `.env` zurückspielen, Datenbank-Dump einspielen, `php artisan optimize`.

---

## 8 Aktualisierung

Änderungen laufen immer über GitHub:

1. Lokal entwickeln, `php vendor/bin/pest` ausführen.
2. Bei Änderungen an Oberflächen-Klassen die Assets bauen: `npm ci && npm run build`. `public/build` mit committen.
3. Push nach `main`.
4. In Plesk **Git → Jetzt bereitstellen** (Test zuerst, dann Betrieb).

Paketaktualisierungen (`composer update`, `npm update`) nur lokal, testen, dann committen.

---

## 9 Sicherheit im Betrieb

- Die Anwendung setzt selbst: HTTPS-Umleitung, HSTS, Content-Security-Policy nur für den eigenen Server, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`.
- Cookies: `Secure`, `HttpOnly`, `SameSite=Strict`, Sitzungsinhalt verschlüsselt, Sitzungsdauer 120 Minuten Inaktivität.
- Anmeldeversuche: höchstens 5 je Minute (Filament), Fehlversuche stehen im Protokoll.
- `robots.txt` sperrt alle Suchmaschinen.
- Hinter nginx vertraut die Anwendung nur `127.0.0.1` als Proxy.

---

## 10 sipgate (Anruf per Klick und automatische Protokollierung)

Das CRM spricht die sipgate-REST-API an (OAuth2, Rechte `sessions:calls:write`, `rtcm:write`, `channels:read`, `history:read`, `devices:read`). Bei Neo schickt das CRM den Channel mit, in dem das gewählte Gerät der Person eingetragen ist, sonst den ersten Channel der Person. Ohne Channel nimmt sipgate den Standard-Channel des Geräts, und den hat die Handy-App nicht: Die API meldet Erfolg, das Handy klingelt aber nicht. „Mobile Geräte“ lehnt sipgate ab („Invalid invitable device“). Die Handy-App („Mobile App“) nimmt sipgate zwar an, sie klingelt aber nicht. Nach Auskunft von sipgate (07.10.2026) wird die mobile App als Ziel für den Anruf per Klick nicht unterstützt. Es funktioniert mit „CLINQ App Extension“, also der sipgate-App am Rechner. Die Seite Telefonie zeigt bei Neo die Channels und die Geräte darin. sipgate hat zwei Anlagen: Bei der klassischen startet das CRM Anrufe über `/sessions/calls`, bei der neuen Anlage „Neo“ über `/calls` (dafür das Recht `rtcm:write`). Welche Anlage ein Konto hat, steht im Zugriffstoken (`featureScope`: `CLASSIC` oder `NEO_PBX`), das CRM wählt den Weg selbst. Seit 07.10.2026: Vorher kannte das CRM nur den klassischen Weg, Neo-Konten bekamen „sipgate hat den Anruf abgelehnt (403)“. Wer vor dieser Änderung verbunden hat, muss sipgate unter Telefonie einmal trennen und neu verbinden. Jede Person verbindet ihr **eigenes** sipgate-Konto unter **Akquise → Telefonie**. Ohne Einträge in der `.env` bleibt die Funktion aus, der Rest des CRM läuft unverändert.

### 10.1 Einmalig in sipgate

1. In der sipgate-Konsole unter den API-Clients den Client für das CRM öffnen (je Umgebung am besten ein eigener Client).
2. **Weiterleitungs-URI** (Redirect URI) eintragen:
   - Test: `https://crm-test.adk-akademie.de/sipgate/callback`
   - Betrieb: `https://crm.adk-akademie.de/sipgate/callback`
3. **Secret neu erzeugen**, wenn es jemals außerhalb von sipgate und der `.env` stand (z. B. in einem Chat oder auf einem Screenshot).

### 10.2 Einmalig auf dem Server (nur Verwaltung)

In der `.env` der Umgebung eintragen (Plesk → Dateien → `httpdocs/.env`, oder per SSH):

```dotenv
SIPGATE_CLIENT_ID=...
SIPGATE_CLIENT_SECRET=...
```

Danach im Anwendungsverzeichnis:

```bash
/opt/plesk/php/8.4/bin/php artisan optimize
```

Client-ID und Secret stehen nur in der `.env`, nie im Repository, nie im Chat.

### 10.3 Je Person

1. **Telefonie → Mit sipgate verbinden**, bei sipgate anmelden und die Freigabe bestätigen. Das CRM sieht das sipgate-Kennwort nicht.
2. Das Gerät wählen, das bei „Anrufen“ zuerst klingeln soll (Tischtelefon, Softphone oder Handy).

### 10.4 Was passiert

- **Über sipgate anrufen** (Anrufliste und Vorgangsseite): Erst klingelt das gewählte Gerät, nach dem Abheben wählt sipgate die Nummer. Sperrliste und Einwilligung prüft das CRM vorher, genau wie beim normalen Anruf.
- **Abgleich alle 5 Minuten** (`adk:sipgate-abgleich`, läuft über den Scheduler aus Abschnitt 5): Neue Anrufe aus der sipgate-Anrufliste werden als Aktivität „Telefonat (sipgate)“ mit Richtung, Ergebnis und Dauer am Vorgang mit derselben Nummer gespeichert, jeder Anruf nur einmal. Anrufe mit unbekannten Nummern werden nicht übernommen und nicht gespeichert.
- Tokens liegen verschlüsselt in der Datenbank (Schlüssel `APP_KEY`). Verbinden, Trennen und jeder gestartete Anruf stehen im Protokoll, ohne Tokens. Wird ein Konto gesperrt, löscht das CRM die sipgate-Verbindung sofort.
- Die Auswertung zeigt zusätzlich „Telefonate laut sipgate“ mit Gesprächszeit als Gegenprobe.

Von Hand abgleichen: Knopf **Jetzt abgleichen** auf der Seite Telefonie, oder

```bash
/opt/plesk/php/8.4/bin/php artisan adk:sipgate-abgleich
```

### 10.5 Datenschutz

- Auftragsverarbeitungsvertrag mit sipgate prüfen bzw. abschließen (sipgate ist ohnehin Telefonanbieter, neu ist der Abruf der Anrufliste durch das CRM).
- Verzeichnis der Verarbeitungstätigkeiten (A-11) und Löschkonzept (A-21) ergänzen: Rufnummernabgleich, Richtung, Ergebnis und Dauer als Aktivität am Vorgang; gelöscht mit dem Vorgang nach den Fristen in `config/adk.php`.
- Testen auf `crm-test` nur mit eigenen Nummern, weil dort echte Anrufe ausgelöst werden.

---

## 11 Dokumente und Dubletten (ab 04.10.2026)

### 11.1 Hochladen bis 20 MB

Die Grenze steht in `config/adk.php` (`documents.max_kb`). PHP muss mitspielen: `public/.user.ini` setzt `upload_max_filesize = 20M` und `post_max_size = 25M`. Falls Plesk das überschreibt, in **Websites & Domains → PHP-Einstellungen** dieselben Werte eintragen.

Prüfen: eine PDF mit etwa 15 MB im Förderfall hochladen. Erscheint „Datei zu groß“ schon beim Auswählen, greift die PHP-Grenze noch nicht.

### 11.2 Ablage

Dokumente liegen verschlüsselt unter `storage/app/documents/<Jahr>/<zufällige Kennung>.enc`, nie öffentlich erreichbar. Abruf nur über das CRM mit Rechteprüfung, jeder Abruf im Protokoll. Sicherung siehe Abschnitt 7.

### 11.3 Dublettenprüfung einmalig für den Bestand

Nach dem ersten Einspielen der Dublettenprüfung einmal den ganzen Bestand prüfen, entweder in der Oberfläche (**Stammdaten → Dublettenprüfung → Bestand prüfen**) oder per Konsole:

```bash
/opt/plesk/php/8.4/bin/php artisan adk:dubletten-pruefen
```
---

## 12 Betrieb crm.adk-akademie.de (eingerichtet 05.10.2026)

| Punkt | Stand |
|---|---|
| Abonnement | `crm.adk-akademie.de`, Paket Unlimited, eigene Domain wie crm-test |
| Systembenutzer | `crmadk`, Shell `/bin/bash` (nötig für die Bereitstellungsaktionen) |
| Dokumentstamm | `httpdocs/public` |
| PHP | 8.4 FPM; `memory_limit` 256M, `max_execution_time` 120, `upload_max_filesize` 20M, `post_max_size` 25M, `expose_php` Off |
| Zertifikat | Let’s Encrypt, automatische Verlängerung durch Plesk; HTTP → HTTPS |
| Datenbank | MariaDB `adk_crm`, Benutzer `adk_crm`. Kennwort hat Janosch vergeben, steht nur in der `.env` und im Kennwortmanager |
| `.env` | aus `.env.example`: `APP_ENV=production`, `APP_DEBUG=false`, eigener `APP_KEY`; Rechte 640 |
| Git | Repository `adk-crm` mit eigenem Deploy-Schlüssel (nur lesen), Bereitstellung **manuell**, Aktionen wie Abschnitt 4 |
| Zeitplan | `/etc/cron.d/adk-crm`, jede Minute `schedule:run` als `crmadk` |
| Einstellungen | Prüfstufen, Förderweg-Schritte, Checkliste von crm-test übernommen (`adk:einstellungen`) |
| Header | „X-Powered-By: PleskLin“ serverweit aus (`/usr/local/psa/admin/conf/panel.ini`, `[webserver] xPoweredByHeader = off`) |
| Sicherung | Plesk-Backup-Manager: ganzer Server täglich 03:00 (nach dem Löschlauf), inkrementell, wöchentlich vollständig, 4 Vollsicherungen (rund 30 Tage), Speicher `/var/lib/psa/dumps`, E-Mail bei Fehlern. Außerhalb des Servers: die bei IONOS gebuchte Datensicherung |
| Erstes Konto | Verwaltung, `j.baum@adk-akademie.de`, angelegt 05.10.2026; Zwei-Faktor-Anmeldung richtet Janosch bei der ersten Anmeldung ein |
| Konto Vertrieb | angelegt von Janosch im CRM |
| sipgate | eingerichtet 05.10.2026 nur im Betrieb (nicht auf crm-test): Client-ID und Secret in der `.env`, eingegeben von Janosch; Redirect-URI `https://crm.adk-akademie.de/sipgate/callback`. Jede Person verbindet ihr Konto unter Akquise → Telefonie |

**Konten im Betrieb anlegen:** Das Kennwortfeld von `adk:benutzer-anlegen` bricht beim Einfügen über das Plesk-Web-Terminal ab („Cancelled“). Weitere Konten deshalb im CRM unter **Verwaltung → Benutzer** anlegen.

**Neue Fassung einspielen:** Plesk → Websites & Domains → `crm.adk-akademie.de` → Git → **Jetzt bereitstellen**. Vorher auf crm-test prüfen.
---

## 13 Website adk-akademie.de (umgezogen von df.eu am 06.10.2026)

Die Website liegt seit dem 06.10.2026 auf demselben Server wie das CRM. Quelle ist das Paket `ADK_Website_FTP_2026-10-06.zip` (OneDrive, Marketing → Corporate Design → webseite → Neue Webseite September). Darin steht in `LIESMICH.txt`, was die Gestaltung ändern darf.

| Punkt | Stand |
|---|---|
| Abonnement | `adk-akademie.de`, Paket Unlimited, Systembenutzer `adkweb` (keine Shell) |
| Dokumentstamm | `httpdocs`, statische Seiten plus `kontakt.php`, `kursheft.php`, `bestaetigen.php` |
| Formulardaten | SQLite `adk-daten/adk.sqlite` **außerhalb** von `httpdocs` (`/var/www/vhosts/adk-akademie.de/adk-daten`, Rechte 770) |
| E-Mail | Mail-Dienst der Domain in Plesk **an** (sonst sperrt Plesk den Versand der Formulare: „The user adkweb is not allowed to send email“). Damit nichts lokal hängen bleibt, gilt eine feste Regel in Postfix: `/etc/postfix/transport_extern` mit `adk-akademie.de smtp:`, eingebunden als erstes in `transport_maps`. Alles an `@adk-akademie.de` geht so an den MX von Microsoft 365. Keine Postfächer auf dem Server. Eingerichtet 06.10.2026 mit Freigabe von Janosch, Sicherung `/root/postfix-main.cf.vor-2026-10-06` |
| DNS | DNS-Dienst der Domain in Plesk aus, die Einträge liegen bei IONOS. A `@` und `www` → 217.160.106.171. SPF: `v=spf1 ip4:217.160.106.171 ip4:92.205.174.49 include:_spf-eu.ionos.com include:spf.protection.outlook.com ~all` (df.eu-Adresse raus, sobald df.eu gekündigt ist) |
| PHP | 8.4 FPM, `expose_php` Off, PDO SQLite vorhanden |
| Zertifikat | Let's Encrypt für `adk-akademie.de` und `www`, automatische Verlängerung; HTTP → HTTPS (Plesk und `.htaccess`), www → ohne www (Plesk, bevorzugte Domain) |
| HSTS | in `.htaccess` eingeschaltet, `max-age=31536000; includeSubDomains`. Geprüft: alle Subdomains (crm, crm-test, Microsoft-365-Einträge) laufen über HTTPS. **Neue Subdomains nur mit HTTPS anlegen** |
| Protokolle | Besucherstatistik aus. Webserver-Protokolle täglich rotiert, 28 Stück, also gelöscht nach spätestens 30 Tagen wie in der Datenschutzerklärung |
| Löschlauf | `/etc/cron.d/adk-website`, täglich 02:40 als `adkweb`, vor der Sicherung um 03:00; Ausgabe in `adk-daten/loeschlauf.log` (nur Zahlen) |
| Nicht hochgeladen | `LIESMICH.txt`, `pruefsummen.txt`, `download/PLATZHALTER.txt` (gehören zum Paket, nicht ins Netz) |
| Geprüft | `inc/` und `bin/` 403, `.htaccess` 403, Datenbank nicht erreichbar, Schutz-Kopfzeilen und Inhaltsrichtlinie kommen an, keine Meldung in der Konsole |

**Auf dem Server angepasst** (bei einem neuen Paket wieder nötig, besser gleich ins Paket übernehmen):

- `.htaccess`: Abschnitt 1 (HTTPS-Umleitung) und HSTS-Zeile eingeschaltet. Die Fassung aus dem Paket liegt unter `/root/adk-website-htaccess.orig`.
- `datenschutz.html`, Abschnitt 7, IONOS: „Verarbeitung ausschließlich in Rechenzentren in der Europäischen Union“ statt „… in Deutschland“. Der Standort des Servers ist nicht als Deutschland zugesichert (siehe `LIVESCHALTUNG.md` 1.4). Freigegeben von Janosch am 06.10.2026.
- `img/weg-2-quadrat-*` (Schritt „Termin bei der Agentur“ auf arbeitsuchende.html und betriebe.html): neues Kalenderbild, gewünscht von Janosch am 06.10.2026. Quelle: `ADK_Website_Bildtausch_Termin_2026-10-06.zip` neben dem Paket, die alten Bilder liegen unter `/root/adk-website-alt/`.
- `download/ADK_Kursheft.pdf` hochgeladen am 06.10.2026 (fehlte im Paket).

**Mailversand der Formulare:** Die Formulare senden über PHP `mail()` an das Postfix des Servers, Absender `noreply@adk-akademie.de`. Postfix liefert direkt beim Empfänger ein, Mails an `@adk-akademie.de` beim MX von Microsoft 365 (wie jeder fremde Mailserver, ohne Anmeldung, ohne Lizenz). SPF enthält die Server-IP. Port 25 ausgehend hat IONOS am 06.10.2026 auf Antrag freigeschaltet. Der Server hat kein IPv6, deshalb `smtp_address_preference = ipv4`. Servername für den Versand: `mail.adk-akademie.de` (A-Eintrag bei IONOS, Reverse DNS der IP im IONOS Cloud Panel, `smtp_helo_name` in Postfix, alle drei am 06.10.2026 gesetzt). DKIM-Selektor `default` (TXT `default._domainkey`), DMARC `v=DMARC1; p=none`. Erste Testmail an info@ am 06.10.2026 angenommen (`250 2.6.0`).

**Versand über Microsoft 365 (seit 07.10.2026):** Hotmail und Yahoo legten Mails direkt vom Server in den Spam. Deshalb gehen Mails mit Absender `@adk-akademie.de` jetzt über Microsoft 365 raus: Postfix `sender_dependent_relayhost_maps = hash:/etc/postfix/relay_by_sender` (`@adk-akademie.de [adkakademie-de0i.mail.protection.outlook.com]:25`), Verbindung immer verschlüsselt (`smtp_tls_policy_maps = hash:/etc/postfix/tls_policy`). Im Exchange-Admin-Center gibt es dafür den Connector **„ADK Webserver (adk-akademie.de, CRM)“**: von „E-Mail-Server Ihrer Organisation“ an Office 365, erkannt an der IP 217.160.106.171. Keine Lizenz, kein Kennwort. Andere Absender (z. B. Plesk-Meldungen) gehen weiter direkt vom Server. Sicherung vorher: `/root/postfix-main.cf.vor-m365-2026-10-07`. Ändert sich die IP des Servers, muss sie im Connector angepasst werden, sonst lehnt Microsoft die Mails ab.

**DKIM bei Microsoft 365:** Bis 07.10.2026 hat Microsoft für adk-akademie.de gar nicht signiert (auch nicht die Outlook-Mails). Schlüssel im Microsoft Defender erzeugt, CNAME `selector1._domainkey` und `selector2._domainkey` bei IONOS eingetragen, Signieren im Defender unter E-Mail-Authentifizierung → DKIM eingeschaltet. Der Schalter sprang zuerst zurück (Status „CnameMissing“), weil Microsoft die CNAMEs erst nach einigen Stunden erkennt; am 07.10.2026 nachmittags blieb er an, Status „Valid / Aktiviert“. Die Standarddomäne `adkakademie.onmicrosoft.com` bleibt ohne DKIM (versendet nichts).

**Prüfen nach Plesk-Updates:** `postconf -h transport_maps` muss mit `hash:/etc/postfix/transport_extern` beginnen. Fehlt der Eintrag, landen Mails an `@adk-akademie.de` im Nichts von Plesk statt bei Microsoft. Wieder setzen: `postconf -e 'transport_maps = hash:/etc/postfix/transport_extern, hash:/var/spool/postfix/plesk/transport' && postfix reload`. Zurück zum alten Stand: Sicherung von `main.cf` einspielen, `postfix reload`, Mail-Dienst der Domain aus (dann kann die Website aber nicht mehr senden).

**Neue Fassung der Website einspielen:** ZIP im Plesk-Dateimanager nach `httpdocs` hochladen, dann im Terminal als root entpacken und die drei Paketdateien weglassen. Anschließend die beiden Anpassungen oben prüfen, solange sie noch nicht im Paket sind. `inc/konfig.php` und `adk-daten` nie überschreiben, ohne vorher nachzusehen.

---

## 14 Website-Eingang: Formulare der Website ins CRM (gebaut 06.10.2026)

Kontaktformular und Kursheft-Anforderung auf adk-akademie.de legen jede Anfrage als Vorgang „Neu“ im CRM an (Kanal Website-Formular, Wiedervorlage heute). Die Mail an info@ bleibt zusätzlich, damit sofort jemand Bescheid weiß.

| Punkt | Stand |
|---|---|
| Schnittstelle | `POST https://crm.adk-akademie.de/api/eingang`, JSON. Herkunft: HMAC-SHA256 über den ganzen Inhalt im Kopf `X-ADK-Signatur`. Ohne Schlüssel antwortet das CRM 503, bei falscher Signatur 401. Höchstens 60 Aufrufe je Minute |
| Schlüssel | `WEBSITE_INTAKE_SECRET` in der `.env` des CRM, derselbe Wert als `crm_schluessel` in `inc/konfig.php` der Website. Steht nirgends sonst, nicht im Paket, nicht im Repository |
| Einmal je Vorgang | Jede Anfrage trägt die Nummer der Website (`kontakt-17`, `kursheft-5`). Kommt sie doppelt, liefert das CRM die vorhandene Vorgangsnummer und legt nichts neu an |
| Sperrliste | Steht E-Mail oder Telefon auf der Sperrliste, legt das CRM nichts an (Protokoll: „Website-Eingang wegen Sperrliste verworfen“, ohne Namen) |
| Anruf | Häkchen „Sie dürfen mich dazu auch anrufen“ gesetzt: Einwilligung Telefonansprache mit Nachweis (Zeitpunkt, gekürzte IP, Seite, Wortlaut). Nicht gesetzt: Kontakt bekommt „Kein Anruf gewünscht“, das CRM sperrt Anrufe und die Anrufliste lässt ihn aus |
| Kursheft | Geht erst nach der Bestätigung (Double-Opt-in) ins CRM. Einwilligung „Kontakt per E-Mail“ mit Nachweis. Finanzierung setzt die Zielgruppe (Agentur → B, Jobcenter → A, Arbeitgeber → Betrieb offen, selbst → Selbstzahler, sonst „Zuordnung offen“) |
| Zielgruppe „Zuordnung offen“ | Für Anfragen, bei denen A oder B noch nicht feststeht. Vor „Übergeben an Förderweg“ muss sie gewählt werden |
| CRM nicht erreichbar | Die Website nimmt die Anfrage trotzdem an. `bin/crm_nachlauf.php` versucht es stündlich erneut, höchstens zehnmal; danach Mail an info@ „Übergabe an das CRM fehlgeschlagen“, Vorgang von Hand anlegen |
| Daten auf der Website | Sieben Tage nach der Übergabe löscht `bin/loeschlauf.php` Name, E-Mail, Telefon und Nachricht aus der Website-Datenbank. Es bleibt eine Quittung mit der CRM-Nummer |
| Geprüft | 26 Tests im CRM (`tests/Feature/WebsiteIntakeTest.php`); am 06.10.2026 lokal von Formular bis Vorgang durchgespielt: Kontakt, Kursheft mit Bestätigung, CRM aus und Nachlauf, Löschung nach sieben Tagen |

**Eingerichtet im Betrieb am 06.10.2026** (Schritte 1 bis 4, Freigabe Janosch). Sicherungen: `/root/adk-website-konfig.php.vor-crm`, `/root/adk-website-vor-update-2026-10-06.tgz`. Beim ersten Nachlauf gingen die zwei Test-Anfragen von Janosch vom Nachmittag ins CRM.

**Achtung beim Hochladen von ZIP-Dateien aus Windows:** Die Dateien kommen mit Rechten 666 an. Danach `chmod 644` auf die Dateien (Verzeichnisse 755) und mit `find httpdocs -perm /022` prüfen.

**Einrichtung im Betrieb** (einmalig, in dieser Reihenfolge):

1. CRM-Stand mit der Schnittstelle einspielen: crm-test übernimmt ihn automatisch nach dem Push; im Betrieb Plesk → `crm.adk-akademie.de` → Git → **Jetzt bereitstellen** (die Migration läuft mit).
2. Schlüssel erzeugen und in beide Dateien schreiben, ohne dass er angezeigt wird. Im Plesk-Terminal als root:
   ```bash
   K=$(openssl rand -hex 32); E=/var/www/vhosts/crm.adk-akademie.de/httpdocs/.env; if grep -q '^WEBSITE_INTAKE_SECRET=' $E; then sed -i "s/^WEBSITE_INTAKE_SECRET=.*/WEBSITE_INTAKE_SECRET=$K/" $E; else printf '\nWEBSITE_INTAKE_SECRET=%s\n' "$K" >> $E; fi; KF=/var/www/vhosts/adk-akademie.de/httpdocs/inc/konfig.php; sed -i "s|'crm_url'           => ''|'crm_url'           => 'https://crm.adk-akademie.de/api/eingang'|; s|'crm_schluessel'    => ''|'crm_schluessel'    => '$K'|" $KF; unset K; cd /var/www/vhosts/crm.adk-akademie.de/httpdocs && sudo -u crmadk -H /opt/plesk/php/8.4/bin/php artisan optimize >/dev/null && grep -c "crm.adk-akademie.de/api/eingang" $KF
   ```
   Ausgabe `1` heißt: eingetragen. Der Schlüssel besteht nur aus Ziffern und a–f.
3. Die geänderten Website-Dateien hochladen (Liste in der Übergabe vom 06.10.2026).
4. Nachlauf als Cron-Job ergänzen, stündlich, in `/etc/cron.d/adk-website`:
   `20 * * * * adkweb /opt/plesk/php/8.4/bin/php /var/www/vhosts/adk-akademie.de/httpdocs/bin/crm_nachlauf.php >> /var/www/vhosts/adk-akademie.de/adk-daten/crm_nachlauf.log 2>&1`
5. Probe mit eigenen Daten: Kontaktformular und Kursheft. Im CRM erscheinen zwei Vorgänge „Neu“, in info@ zwei Mails.

**Voraussetzung:** Ab Schritt 5 kommen echte Anfragen ins CRM. Dafür gilt `LIVESCHALTUNG.md` Schritt 4 (Sicherung außerhalb des Servers).

---

## 15 E-Mail aus dem CRM (Microsoft 365, gebaut 08.10.2026)

Jede Person schreibt E-Mails direkt aus dem Vorgang („E-Mail schreiben“ auf der Vorgangsseite und in der Anrufliste), und zwar aus ihrem **eigenen** Microsoft-365-Postfach. Das CRM nutzt dafür Microsoft Graph mit **delegierten** Rechten: Es kann nur im Namen der angemeldeten Person senden, nie aus fremden Postfächern, und es liest keine E-Mails (kein `Mail.Read`). Die Mail liegt danach in Outlook unter „Gesendete Elemente“, Antworten kommen wie gewohnt in Outlook an. Ohne Einträge in der `.env` bleibt die Funktion aus (Menüpunkt unsichtbar, Adressen `/microsoft/…` antworten 404), der Rest des CRM läuft unverändert.

Abweichung vom Lastenheft Abschnitt 5 („Versand über info@“): Entscheidung des Vertriebs vom 08.10.2026, Versand aus dem eigenen Postfach, damit Antworten bei der Person landen, die geschrieben hat. Siehe `STUFE1_ABNAHME.md` Abweichung 14.

### 15.1 Einmalig in Microsoft Entra (Verwaltung)

1. [entra.microsoft.com](https://entra.microsoft.com) → **Identität → Anwendungen → App-Registrierungen → Neue Registrierung**
   - Name: `ADK CRM` (für crm-test eine eigene Registrierung, z. B. `ADK CRM Test`)
   - Unterstützte Kontotypen: **Nur Konten in diesem Organisationsverzeichnis** (Single Tenant)
   - Umleitungs-URI: Plattform **Web**, `https://crm.adk-akademie.de/microsoft/callback` (Test: `https://crm-test.adk-akademie.de/microsoft/callback`)
2. In der Übersicht der App **Anwendungs-ID (Client-ID)** und **Verzeichnis-ID (Mandanten-ID)** notieren.
3. **API-Berechtigungen → Berechtigung hinzufügen → Microsoft Graph → Delegierte Berechtigungen:** `offline_access`, `openid`, `profile`, `email`, `User.Read`, `Mail.Send`. **Keine Anwendungsberechtigungen** (die würden Senden aus jedem Postfach erlauben).
4. **Administratorzustimmung für ADK erteilen** (Knopf auf derselben Seite). Ohne Zustimmung meldet das CRM beim Verbinden „Microsoft hat die Verbindung nicht freigegeben“ bzw. dass `Mail.Send` fehlt.
5. **Zertifikate & Geheimnisse → Neuer geheimer Clientschlüssel**, Ablauf 24 Monate. Den **Wert** (nicht die ID) sofort kopieren, er wird nur einmal angezeigt. Direkt in die `.env`, nie in einen Chat oder auf einen Screenshot. **Ablaufdatum in den Kalender:** Vorher einen neuen Schlüssel erzeugen und eintragen, sonst schlagen Verbinden und Senden fehl.

### 15.2 Einmalig auf dem Server (Janosch)

Die Migration `2026_10_08_100000_create_mail_tables` läuft mit der Bereitstellung. Sie legt die Tabellen an und, nur in eine leere Tabelle, die Startvorlagen „Unterlagen nach Telefonat“ und „Nachfassen“.

In der `.env` der Umgebung eintragen (Plesk → Dateien → `httpdocs/.env`, oder per SSH):

```dotenv
MICROSOFT_CLIENT_ID=...
MICROSOFT_CLIENT_SECRET=...
MICROSOFT_TENANT_ID=...
```

Die Mandanten-ID ist bei einer Single-Tenant-App Pflicht. Die Weiterleitungsadresse ergibt sich aus `APP_URL` (`…/microsoft/callback`); nur wenn sie abweicht, zusätzlich `MICROSOFT_REDIRECT_URI` setzen. `/microsoft/callback` läuft bewusst ohne Sitzung und leitet über eine kleine Zwischenseite nach `/microsoft/weiter`. Grund: Das Sitzungscookie ist `SameSite=strict`. Hat man bei Microsoft etwas eingegeben, schickt der Browser es beim Rücksprung nicht mit, und das CRM schickt zur Anmeldung (Schleife, beobachtet am 08.10.2026). Danach im Anwendungsverzeichnis:

```bash
/opt/plesk/php/8.4/bin/php artisan optimize
```

Client-ID, Secret und Mandanten-ID stehen nur in der `.env`, nie im Repository, nie im Chat.

### 15.3 Je Person und Verwaltung

1. **Akquise → E-Mail-Konto → Mit Microsoft 365 verbinden**, bei Microsoft das richtige Konto wählen und die Freigabe bestätigen. Das CRM sieht das Kennwort nicht.
2. Auf derselben Seite die **Signatur** eintragen: Editor mit fett, kursiv und Links, die Outlook-Signatur lässt sich hineinkopieren. Dazu optional ein **Logo** (PNG/JPG, höchstens 300 KB, Breite in Pixel). Das CRM bereinigt das HTML beim Speichern und Senden und bettet das Logo als Inline-Anhang (cid) ein, so erscheint es ohne „Bilder herunterladen“. Das Logo liegt privat unter `storage/app/private/signaturen`.
3. Verwaltung: unter **Verwaltung → E-Mail-Vorlagen** die Startvorlagen prüfen und anpassen, z. B. das Kursheft als PDF an „Unterlagen nach Telefonat“ hängen (eine Datei je Vorlage, höchstens 3 MB). Platzhalter: `{anrede}`, `{vorname}`, `{nachname}`, `{firma}`, `{absender}`.

### 15.4 Was passiert

- **Formular „E-Mail schreiben“:** Vorlage (füllt Betreff und Text), An (Vorschlag: Ansprechperson, sonst Betrieb), Betreff, Text, Anhang der Vorlage, Pflicht-Häkchen „Die Person hat um diese E-Mail gebeten oder eingewilligt“, optional Wiedervorlage mit Uhrzeit. Ohne verbundenes Postfach weist der Knopf auf „E-Mail-Konto“ hin. Sichtbar für offene Vorgänge mit Recht „Vorgänge bearbeiten“.
- **Versand:** `POST https://graph.microsoft.com/v1.0/me/sendMail` mit `saveToSentItems`. Der Text geht als HTML (alles maskiert, Zeilenumbrüche erhalten), die Signatur nach einer Leerzeile. Anhänge zusammen höchstens 3 MB, größere lehnt das CRM vor dem Senden ab.
- **Sperrliste:** Steht die Adresse oder der Vorgang (Telefon, E-Mail, Firma mit PLZ) auf der Sperrliste, sendet das CRM nicht.
- **Verlauf:** Aktivität „E-Mail“ mit Empfänger, Betreff, Vorlage, Anhang, Bestätigung der Einwilligung, gegebenenfalls Wiedervorlage und dem vollen Text. Der letzte Kontakt wird gesetzt, **der Status bleibt**.
- **Protokoll** (Bereich „Microsoft 365“): verbunden, getrennt, E-Mail gesendet (Empfänger, Vorlage, Anhang, Wiedervorlage), ohne Tokens und ohne Text der E-Mail. Änderungen an Vorlagen stehen unter „E-Mail-Vorlage“.
- **Tokens** liegen verschlüsselt in der Datenbank (`APP_KEY`). Das Zugriffstoken gilt etwa eine Stunde und wird automatisch erneuert. Wird die Freigabe widerrufen, das Kennwort geändert oder 90 Tage nicht gesendet, meldet das CRM „Bitte verbinden Sie Ihr Postfach unter „E-Mail-Konto“ neu.“ Wird ein Konto gesperrt, löscht das CRM die Verbindung sofort.
- **Kennung für später:** Graph liefert beim Senden keine Nachrichten-ID. Das CRM setzt deshalb die Kopfzeile `x-adk-crm-ref` mit einer zufälligen Kennung und speichert sie an der Aktivität (`m365:…`). Damit lässt sich die Mail später in „Gesendete Elemente“ wiederfinden.

### 15.5 Datenschutz

- Microsoft verarbeitet die E-Mails der ADK ohnehin als Auftragsverarbeiter (Exchange Online, Datenschutznachtrag in den Microsoft-Produktbedingungen). Neu ist nur, dass das CRM im Namen der Person sendet und den Text im Vorgang speichert. Verzeichnis der Verarbeitungstätigkeiten (A-11) und TOM (A-21) entsprechend ergänzen; gelöscht wird der Text mit dem Vorgang nach den Fristen in `config/adk.php`.
- **§ 7 UWG:** Werbung per E-Mail nur mit vorheriger Einwilligung, auch gegenüber Betrieben. Das Häkchen ist Pflicht, die Bestätigung steht mit Datum und Person im Verlauf. Am Kontakt eingetragene E-Mail-Einwilligungen (Kursheft, Double-Opt-in) zeigt das Formular als Hinweis an.
- **Vorlagen** enthalten keine personenbezogenen Daten, Anhänge nur allgemeine Unterlagen. Sie liegen nicht öffentlich unter `storage/app/private/email-templates` (unverschlüsselt, weil ohne Personenbezug).
- **Kein Tracking:** keine Lesebestätigung, kein Zählpixel, keine Link-Verfolgung. Das CRM hat kein Leserecht auf Postfächer.
- Testen auf `crm-test` nur an eigene Adressen, weil dort echte E-Mails hinausgehen.

### 15.6 Später: Antworten ins CRM

Noch nicht gebaut (Merkliste). Dafür käme das delegierte Recht `Mail.Read` hinzu (neue Administratorzustimmung, jede Person verbindet einmal neu). Zuordnung über die Kennung `x-adk-crm-ref` der gesendeten Mail (deren `conversationId` in „Gesendete Elemente“) bzw. über die Absenderadresse, Abgleich wie bei sipgate alle paar Minuten.
