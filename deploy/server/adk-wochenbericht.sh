#!/bin/bash
# ADK · Wochenbericht des Servers (Plesk-VPS). Läuft als root per /etc/cron.d/adk-wochenbericht,
# jeden Montag 06:30, und schickt eine Mail an EMPFAENGER. Nur Zahlen und Zustände, keine Inhalte
# aus CRM oder Website (keine personenbezogenen Daten). Doku: docs/BETRIEB.md, Abschnitt Wartung.
set -u
export LC_ALL=C.UTF-8 PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

EMPFAENGER="${1:-j.baum@adk-akademie.de}"
ABSENDER="noreply@adk-akademie.de"
CRM=/var/www/vhosts/crm.adk-akademie.de/httpdocs
CRMTEST=/var/www/vhosts/crm-test.adk-akademie.de/httpdocs
WEB=/var/www/vhosts/adk-akademie.de
DUMPS=/var/lib/psa/dumps

warn=()
add_warn() { warn+=("$1"); }
alter_h() { echo $(( ( $(date +%s) - $(stat -c %Y "$1") ) / 3600 )); }

# Updates und Neustart
upd_all=$(apt-get -s -o Debug::NoLocking=1 upgrade 2>/dev/null | grep -c '^Inst ')
upd_sec=$(apt-get -s -o Debug::NoLocking=1 upgrade 2>/dev/null | grep '^Inst ' | grep -ci 'security')
[ "$upd_sec" -gt 0 ] && add_warn "$upd_sec Sicherheitsupdates noch nicht eingespielt"
neustart="nein"
if [ -f /var/run/reboot-required ]; then
    neustart="ja (seit $(alter_h /var/run/reboot-required) h): $(tr '\n' ' ' < /var/run/reboot-required.pkgs 2>/dev/null | cut -c1-200)"
    [ "$(alter_h /var/run/reboot-required)" -gt 48 ] && add_warn "Neustart seit mehr als 2 Tagen nötig"
fi

# Sicherungen (Plesk-Backup-Manager, Server-Sicherung)
letzte_sicherung="keine gefunden"
neueste=$(find "$DUMPS" -maxdepth 1 -name 'backup_info_*.xml' -printf '%T@ %p\n' 2>/dev/null | sort -n | tail -1 | cut -d' ' -f2-)
if [ -n "$neueste" ]; then
    letzte_sicherung="$(date -d @"$(stat -c %Y "$neueste")" '+%d.%m.%Y %H:%M') (vor $(alter_h "$neueste") h)"
    [ "$(alter_h "$neueste")" -gt 30 ] && add_warn "Letzte Sicherung älter als 30 Stunden"
else
    add_warn "Keine Plesk-Sicherung gefunden"
fi
sicherungen_anzahl=$(find "$DUMPS" -maxdepth 1 -name 'backup_info_*.xml' 2>/dev/null | wc -l)
sicherungen_groesse=$(du -sh "$DUMPS" 2>/dev/null | cut -f1)

# Speicher
platte=$(df -P / | awk 'NR==2 {print $5}' | tr -d '%')
[ "$platte" -ge 85 ] && add_warn "Festplatte zu ${platte} % belegt"
platte_text=$(df -h / | awk 'NR==2 {print $3 " von " $2 " belegt (" $5 ")"}')
ram_text=$(free -m | awk '/^Mem:/ {print $3 " von " $2 " MB belegt, verfügbar " $7 " MB"}')

# Dienste
fehl_dienste=$(systemctl --failed --no-legend --plain 2>/dev/null | awk '{print $1}' | tr '\n' ' ')
[ -n "$fehl_dienste" ] && add_warn "Ausgefallene Dienste: $fehl_dienste"

# Zertifikate
zert_text=""
for d in adk-akademie.de crm.adk-akademie.de crm-test.adk-akademie.de; do
    ende=$(echo | timeout 10 openssl s_client -connect "$d:443" -servername "$d" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
    if [ -n "$ende" ]; then
        tage=$(( ( $(date -d "$ende" +%s) - $(date +%s) ) / 86400 ))
        zert_text+="  $d: noch $tage Tage"$'\n'
        [ "$tage" -lt 14 ] && add_warn "Zertifikat $d läuft in $tage Tagen ab"
    else
        zert_text+="  $d: nicht abrufbar"$'\n'
        add_warn "Zertifikat $d nicht abrufbar"
    fi
done

# Mail: Warteschlange (nur Anzahl) und Weiterleitung an Microsoft 365 (BETRIEB.md 13)
mailq_text=$(postqueue -p 2>/dev/null | tail -1)
case "$mailq_text" in *"is empty"*) mailq_text="leer" ;; *) add_warn "Mails in der Warteschlange: $mailq_text" ;; esac
case "$(postconf -h transport_maps 2>/dev/null)" in
    hash:/etc/postfix/transport_extern*) mail_weg="in Ordnung" ;;
    *) mail_weg="FEHLT"; add_warn "Postfix transport_maps ohne transport_extern (Mails an @adk-akademie.de gehen nicht an Microsoft 365)" ;;
esac

# CRM: Fehler im Protokoll der letzten 7 Tage (nur Anzahl), Löschlauf
crm_fehler() {
    find "$1/storage/logs" -maxdepth 1 -name 'laravel*.log' -mtime -7 -print0 2>/dev/null \
        | xargs -0 -r grep -h -E '^\[[0-9-]+ [0-9:]+\] [a-z]+\.(ERROR|CRITICAL|ALERT|EMERGENCY)' 2>/dev/null \
        | awk -v grenze="$(date -d '7 days ago' '+%Y-%m-%d')" 'substr($1,2,10) >= grenze' | wc -l
}
crm_fehler_live=$(crm_fehler "$CRM")
crm_fehler_test=$(crm_fehler "$CRMTEST")
[ "$crm_fehler_live" -gt 0 ] && add_warn "CRM: $crm_fehler_live Fehler im Protokoll (7 Tage)"
loesch_crm="kein Protokoll"
if [ -f "$CRM/storage/logs/loeschlauf.log" ]; then
    loesch_crm="zuletzt vor $(alter_h "$CRM/storage/logs/loeschlauf.log") h"
    [ "$(alter_h "$CRM/storage/logs/loeschlauf.log")" -gt 30 ] && add_warn "CRM-Löschlauf seit mehr als 30 Stunden nicht gelaufen"
fi

# Website: Löschlauf und CRM-Nachlauf (Protokolle enthalten nur Zahlen)
web_log() {
    if [ -f "$WEB/adk-daten/$1" ]; then
        echo "vor $(alter_h "$WEB/adk-daten/$1") h: $(tail -1 "$WEB/adk-daten/$1" | cut -c1-120)"
    else
        echo "kein Protokoll"
    fi
}
web_loesch=$(web_log loeschlauf.log)
web_nachlauf=$(web_log crm_nachlauf.log)

# Anmeldeschutz
f2b=$(fail2ban-client status 2>/dev/null | sed -n 's/.*Jail list:\s*//p')
f2b_gesperrt=0
for j in $(echo "$f2b" | tr ',' ' '); do
    n=$(fail2ban-client status "$j" 2>/dev/null | sed -n 's/.*Currently banned:\s*//p')
    f2b_gesperrt=$(( f2b_gesperrt + ${n:-0} ))
done

if [ ${#warn[@]} -eq 0 ]; then
    betreff="ADK-Server: alles in Ordnung ($(date '+%d.%m.%Y'))"
    kopf="ALLES IN ORDNUNG"
else
    betreff="ADK-Server: ${#warn[@]} Hinweis(e) ($(date '+%d.%m.%Y'))"
    kopf="BITTE ANSEHEN:"$'\n'"$(printf '  - %s\n' "${warn[@]}")"
fi

{
    echo "From: ADK Server <$ABSENDER>"
    echo "To: $EMPFAENGER"
    echo "Subject: $betreff"
    echo "Content-Type: text/plain; charset=UTF-8"
    echo "Content-Transfer-Encoding: 8bit"
    echo
    echo "$kopf"
    echo
    echo "Server $(hostname) · $(date '+%d.%m.%Y %H:%M') · läuft seit $(uptime -p | sed 's/^up //')"
    echo "Plesk: $(plesk version 2>/dev/null | sed -n 's/^Product version:\s*//p')"
    echo
    echo "Updates offen: $upd_all, davon Sicherheit: $upd_sec"
    echo "Neustart nötig: $neustart"
    echo
    echo "Letzte Sicherung: $letzte_sicherung · $sicherungen_anzahl Sicherungen, zusammen $sicherungen_groesse"
    echo "Festplatte: $platte_text"
    echo "Arbeitsspeicher: $ram_text"
    echo "Ausgefallene Dienste: ${fehl_dienste:-keine}"
    echo
    echo "Zertifikate:"
    printf '%s' "$zert_text"
    echo
    echo "Mail-Warteschlange: $mailq_text · Weiterleitung an Microsoft 365: $mail_weg"
    echo
    echo "CRM Fehler im Protokoll (7 Tage): Betrieb $crm_fehler_live, Test $crm_fehler_test"
    echo "CRM Löschlauf: $loesch_crm"
    echo "Website Löschlauf: $web_loesch"
    echo "Website CRM-Nachlauf: $web_nachlauf"
    echo
    echo "Anmeldeschutz (Fail2Ban): derzeit $f2b_gesperrt Adressen gesperrt"
    echo
    echo "Dieser Bericht kommt jeden Montag. Einrichtung und Bedeutung: docs/BETRIEB.md, Abschnitt Wartung."
} | /usr/sbin/sendmail -t -f "$ABSENDER"
