# YTAN Worker

Berechnet YTAN-Jobs, die auf dem YTAN-Server als **„Extern berechnen“** markiert sind (`/admin/wind`, `/admin/radar`), und lädt die Ergebnisse auf den Server. Der Server rechnet diese Jobs dann nicht mehr selbst; welcher Job wo läuft, wird nur auf dem Server eingestellt.

## Ablauf

Alle `tick_minutes` Minuten:

1. `radar/config.json` vom Server holen (Regionen, Job-Einstellungen, welche Jobs extern laufen).
2. Jeden externen Job ausführen, wenn er fällig ist (gleiche Zeitfenster wie auf dem Server) oder auf der Admin-Seite „Jetzt aktualisieren“ gedrückt wurde.
3. Ergebnis hochladen: erst die Dateien, dann `status.json`, zuletzt `manifest.json`; danach Aufräumen auf dem Server.
4. Lebenszeichen `worker-status.json` hochladen (die Admin-Seiten zeigen „letzter Kontakt vor … min“) und Sensoren in Home Assistant setzen.

Nur der Worker verbindet sich – per SSH/rsync zum Server. Der Server verbindet sich nie zum Home-Assistant-System, es ist keine Portfreigabe nötig.

## Einrichtung

1. Add-on starten. Das Protokoll zeigt beim Start eine Zeile, in der nach Zeit und `[addon]` der Text `command="/usr/bin/rrsync ...` folgt.
2. Diese Zeile ab `command=` auf dem Server in `~/.ssh/authorized_keys` des SSH-Benutzers eintragen (Verzeichnis `.ssh` mit Rechten 700, Datei 600). Der Schlüssel kann damit **nur** rsync unterhalb des YTAN-Ordners `storage/` – keine Shell, kein Zugriff auf andere Websites.
3. Auf dem Server in `/admin/wind` den Schalter „Extern berechnen“ einschalten. Ab dem nächsten Takt rechnet der Worker.

## Optionen

| Option | Bedeutung |
|---|---|
| `server_host`, `server_user` | SSH-Ziel des YTAN-Servers |
| `server_storage` | absoluter Pfad des `storage/`-Ordners auf dem Server (nur für die angezeigte `authorized_keys`-Zeile) |
| `workers` | Threads/Prozesse je Job (Standard 2, schont Home Assistant) |
| `tick_minutes` | Abstand der Takte (Standard 5) |
| `host_name` | Name, den die Admin-Seiten anzeigen |

## Protokoll

Jede Zeile beginnt mit Datum und Uhrzeit (Ortszeit), danach steht in eckigen Klammern, woher sie kommt:

- `[addon]` – das Add-on selbst (Start, SSH-Schlüssel, Absturzmeldungen)
- `[worker]` – der Takt: Konfiguration holen, welche Jobs laufen, Upload
- `[wind]`, `[radar]` – der jeweilige Job

Beispiel: `2026-11-13 09:18:26 [worker] INFO wind: exit 0 in 3.3 s, upload ok`. Dieselben Zeilen (ohne die Klammer) liegen in `/data/storage/logs/{worker,wind,radar}.log` und auf dem Server unter `/admin/logs` (Quelle „YTAN-Worker“).

## Sensoren

- `sensor.ytan_worker` – `ok`/`error`, Attribute `last_tick`, `external_jobs`
- `sensor.ytan_worker_wind` (und je weiterem externen Job) – Ergebnis des letzten Laufs (`processed`, `unchanged`, `error`, …), Attribute `duration_s`, `published`, `last_run_end`

Die Sensoren werden nach einem Neustart von Home Assistant beim nächsten Takt neu gesetzt.

Beispiel-Automation „seit 4 Stunden kein Takt“:

```yaml
alias: YTAN Worker meldet sich nicht
triggers:
  - trigger: template
    value_template: >
      {{ as_timestamp(now()) - as_timestamp(state_attr('sensor.ytan_worker', 'last_tick'), 0) > 4 * 3600 }}
actions:
  - action: notify.notify
    data:
      message: Der YTAN Worker hat seit 4 Stunden keinen Takt gemeldet.
```

## Daten

Die Job-Daten liegen im Add-on unter `/data/storage` (auf der Daten-SSD) und sind aus den Home-Assistant-Backups ausgenommen – sie werden jederzeit neu berechnet. Der SSH-Schlüssel (`/data/ssh`) bleibt erhalten.
