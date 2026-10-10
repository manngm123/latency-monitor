# Latenz-Monitoring

Einfaches Latenz- und Paketverlust-Monitoring für den Heimanschluss.
Ein Cronjob pingt alle 3 Minuten vier Ziele an und schreibt die Ergebnisse als CSV.
Eine PHP-Seite zeigt sie als Statuskarten, Diagramme und Tabelle an.

## Ziele

| Name       | Adresse        | Zweck                       |
|------------|----------------|-----------------------------|
| FritzBox   | 192.168.178.1  | lokales Netz / Router       |
| Cloudflare | 1.1.1.1        | Internet (Anycast)          |
| Heise      | 193.99.144.80  | Internet (DE-Ziel)          |
| Google     | 8.8.8.8        | Internet (Anycast)          |

Die Ziele werden in den Skripten in `TARGET_NAMES` und `TARGETS` gepflegt.

## Komponenten

| Datei                                   | Funktion                                        |
|-----------------------------------------|-------------------------------------------------|
| `/opt/latency/latency-log.sh`           | Messung: 10 Pings pro Ziel, Intervall 0,5 s, Ergebnis als CSV-Zeile |
| `/var/spool/cron/crontabs/manngm`       | Cronjob, startet die Messung alle 3 Minuten     |
| `/srv/www/default/htdocs/latency.php`   | Weboberfläche mit Tagesansicht und Navigation   |
| `/var/lib/latency-monitor/`             | Ablage der Tagesdateien `latency-YYYY-MM-DD.csv` |
| `/opt/latency/continuous-ping.sh`       | optional, aktuell nicht im Einsatz (siehe unten) |

## Installation

```bash
# Skripte ausführbar machen
sudo chmod +x /opt/latency/*.sh

# Datenverzeichnis anlegen (Cron-User schreibt, Webserver liest)
sudo install -d -o manngm -g manngm -m 755 /var/lib/latency-monitor

# Cronjob als manngm einrichten
crontab -e
```

Crontab-Eintrag:

```cron
PATH=/usr/local/bin:/usr/bin:/bin
*/3 * * * * /opt/latency/latency-log.sh >/dev/null 2>&1
```

### Voraussetzungen

- `ping` (iputils), GNU `grep` mit `-P`, `awk`, `flock`
- Der Cron-User darf ICMP senden (`ping -c1 1.1.1.1` als `manngm` testen)
- PHP ≥ 7.4 auf dem Webserver; der Webserver-User braucht Lesezugriff auf `/var/lib/latency-monitor` (ggf. `open_basedir` freigeben)
- Der Browser lädt Chart.js von `cdn.jsdelivr.net`

## Funktionsweise

- `latency-log.sh` läuft über `flock` nie parallel (`/tmp/latency-monitor.lock`).
- Bei einer neuen Tagesdatei wird zuerst ein Warm-up ausgeführt (3 Pings pro Ziel, nicht geloggt). Dadurch verfälschen ARP- und Cache-Effekte die erste Messung nicht.
- Den Paketverlust berechnet das Skript selbst aus `sent` und `received`. Die PHP-Seite rechnet ihn beim Einlesen noch einmal nach.

### CSV-Format

```
timestamp,target,address,sent,received,loss_pct,rtt_min,rtt_avg,rtt_max,rtt_mdev
2026-10-10 15:54:01,FritzBox,192.168.178.1,10,10,0.0,0.280,0.300,0.370,0.020
```

Wenn ein Ziel nicht erreichbar ist, bleiben die RTT-Felder leer.

## Weboberfläche

Aufruf: `http://<host>/latency.php`, ein bestimmter Tag über `?date=YYYY-MM-DD`.

- Statuskarten pro Ziel mit aktueller Latenz, Tages-Ø, Min/Max, Jitter und Paketverlust
- Diagramme für Latenz (RTT avg) und Paketverlust über den Tag
- Tabelle mit allen Messungen des Tages, neueste zuerst
- Navigation zwischen den vorhandenen Tagen
- Die Seite für den aktuellen Tag lädt sich alle 3 Minuten neu

Status-Ampel:

| Ziel          | Grün      | Gelb       | Rot                  |
|---------------|-----------|------------|----------------------|
| FritzBox      | < 1 ms    | < 5 ms     | ≥ 5 ms               |
| Internetziele | < 25 ms   | < 50 ms    | ≥ 50 ms              |
| Paketverlust  | 0 %       | > 0 %      | ≥ 5 % / unerreichbar |

Der Paketverlust hat Vorrang vor der Latenz.

## Prüfen und Fehlersuche

```bash
# Messung manuell ausführen
sudo -u manngm /opt/latency/latency-log.sh
tail /var/lib/latency-monitor/latency-$(date +%F).csv

# Lesezugriff des Webservers prüfen (User je nach Distribution: wwwrun / www-data)
sudo -u wwwrun head -2 /var/lib/latency-monitor/latency-$(date +%F).csv
```

Wenn keine Daten erscheinen:

- Ist der Cronjob aktiv, also nicht auskommentiert? `crontab -l`
- Zur Fehlersuche die Ausgabe vorübergehend in ein Log umleiten:
  `... latency-log.sh >>/var/lib/latency-monitor/cron.log 2>&1`

## Aufräumen (optional)

Pro Tag entsteht eine Datei mit etwa 480 Zeilen. Alte Dateien lassen sich per Cron löschen:

```cron
15 3 * * * find /var/lib/latency-monitor -name 'latency-*.csv' -mtime +90 -delete
```

## continuous-ping.sh (nicht aktiv)

Dauerping mit 0,5 s Intervall. Das Skript erkennt Paketverlust anhand von Lücken in `icmp_seq` und schreibt nach `/var/lib/latency-monitor/continuous/`. Es wird derzeit nicht gestartet, und die Weboberfläche zeigt seine Daten nicht an.

Für einen späteren Einsatz:

- Das Skript läuft endlos und darf nicht per `*/n`-Cron gestartet werden. Besser ist ein systemd-Service mit `Restart=always`.
- Es erzeugt rund 40 MB pro Tag, Aufräumen ist also Pflicht.
