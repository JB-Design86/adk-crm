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

Der Scheduler startet täglich um 02:30 Uhr den Löschlauf `adk:loeschlauf` (Uhrzeit in `config/adk.php`, `retention.run_at`). Die Ausgabe landet in `storage/logs/loeschlauf.log`, Anzahl und Zeitpunkt zusätzlich im Protokoll der Anwendung.

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
| **`.env`** | `httpdocs/.env` | `APP_KEY` wird zum Entschlüsseln gebraucht (Sitzungen, Zwei-Faktor-Geheimnisse). **Ohne den alten `APP_KEY` müssen alle Konten die Zwei-Faktor-Anmeldung neu einrichten.** Zusätzlich im Passwortmanager ablegen |
| `storage/app` | `httpdocs/storage/app` | in Stufe 1 praktisch leer; ab Stufe 4 Dokumente der Teilnehmerakte |
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
