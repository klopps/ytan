# YTAN Worker

Berechnet YTAN-Jobs (Wind, Regenradar) und lädt die Ergebnisse auf den Server. **Welche Jobs dieser Worker rechnet, steht nur auf dem Server:** unter `Admin > Worker` hat jeder Job eine Prioritätenliste aus angelegten Workern und dem Server selbst. Es rechnet der erste aktivierte Eintrag, der sich meldet und den Job kann; meldet er sich nicht mehr, springt der nächste ein. Jeder Worker hat einen eindeutigen **Namen** (Option `worker_id`).

## Ablauf

Alle `tick_minutes` Minuten:

1. `radar/config.json` (Regionen, Job-Einstellungen, die Prioritätenlisten) und den Zustand der anderen holen: die Berichte `workers/*/worker-status.json`, das Lebenszeichen des Server-Crons und den Stand der Jobs.
2. Prüfen, ob ein anderes System unter demselben Namen berichtet (Namenskonflikt). Dann rechnet dieses System nicht, markiert den Konflikt und meldet einen Fehler.
3. Jeden Job ausführen, den die Listen diesem Worker geben, wenn er fällig ist (gleiche Zeitfenster wie auf dem Server; hat der Vorgänger im selben Fenster gerade gerechnet, wiederholt der Vertreter es nicht) oder auf der Admin-Seite „Jetzt aktualisieren“ gedrückt wurde.
4. Ergebnis hochladen: erst die Dateien, dann `status.json`, zuletzt `manifest.json`; danach Aufräumen auf dem Server.
5. Den Bericht `workers/<Name>/worker-status.json` hochladen (Name, was der Worker kann, was er tat; die Admin-Seiten zeigen „letzter Kontakt vor … min“) und Sensoren in Home Assistant setzen.

Ein Worker, der in keiner Liste steht, meldet sich nur. Nur der Worker verbindet sich – per SSH/rsync zum Server. Der Server verbindet sich nie zum Home-Assistant-System, es ist keine Portfreigabe nötig.

## Einrichtung

1. Auf dem Server unter `Admin > Worker` den Namen anlegen (z. B. `homeassistant`) und dieselbe Schreibweise in der Option `worker_id` eintragen. Das Anlegen erzeugt auch die Verzeichnisse `storage/workers/` und `storage/logs/worker/`, die der erste Upload braucht.
2. Add-on starten. Das Protokoll zeigt beim Start eine Zeile, in der nach Zeit und `[addon]` der Text `command="/usr/bin/rrsync ...` folgt.
3. Diese Zeile ab `command=` auf dem Server in `~/.ssh/authorized_keys` des SSH-Benutzers eintragen (Verzeichnis `.ssh` mit Rechten 700, Datei 600). Der Schlüssel kann damit **nur** rsync unterhalb des YTAN-Ordners `storage/` – keine Shell, kein Zugriff auf andere Websites. **Ein eigener Schlüssel je Worker:** Der Schlüssel des Add-ons nie auf ein zweites System kopieren.
4. Unter `Admin > Worker` den Worker in die Listen der Jobs aufnehmen, die er rechnen soll, und speichern. Ab dem nächsten Takt rechnet er.

**Sperren:** Zum sofortigen Aussperren die `authorized_keys`-Zeile dieses Workers auf dem Server löschen. Der Server kann die Schlüssel nicht unterscheiden (rrsync): Ein Worker kann alles unter `storage/` lesen und schreiben. Mehr dazu auf der Seite `Admin > Worker` („Wie sicher ist die Anmeldung?“).

## Optionen

| Option | Bedeutung |
|---|---|
| `server_host`, `server_user` | SSH-Ziel des YTAN-Servers |
| `server_storage` | absoluter Pfad des `storage/`-Ordners auf dem Server (nur für die angezeigte `authorized_keys`-Zeile) |
| `workers` | Threads/Prozesse je Job (Standard 2, schont Home Assistant) |
| `tick_minutes` | Abstand der Takte (Standard 5) |
| `worker_id` | eindeutiger Name dieses Workers, am Server angelegt (a-z, 0-9, `-`, höchstens 32 Zeichen; Standard `homeassistant`) |
| `jobs` | was dieses System kann, kommagetrennt (`radar,wind`); leer = alle. Ein Job, der hier fehlt, wird diesem Worker nie zugeteilt |
| `address` | eine Adresse, die `Admin > Worker` anzeigt (nur Anzeige, z. B. `192.168.178.11`) |
| `host_name` | Rechnername, den die Admin-Seiten anzeigen |
| `arome_api_key` | Schlüssel für die Météo-France-AROME-Schnittstelle (Windjob); leer = der Job läuft ohne AROME |

## Protokoll

Jede Zeile beginnt mit Datum und Uhrzeit (Ortszeit), danach steht in eckigen Klammern, woher sie kommt:

- `[addon]` – das Add-on selbst (Start, SSH-Schlüssel, Absturzmeldungen)
- `[worker]` – der Takt: Konfiguration holen, welche Jobs laufen, Upload
- `[wind]`, `[radar]` – der jeweilige Job

Beispiel: `2026-11-13 09:18:26 [worker] INFO wind: exit 0 in 3.3 s, upload ok`. Dieselben Zeilen (ohne die Klammer) liegen in `/data/storage/logs/{worker,wind,radar}.log` und auf dem Server unter `/admin/logs` (Quelle „YTAN-Worker <Name>“).

## Sensoren

- `sensor.ytan_worker` – `ok`/`error`, Attribute `last_tick`, `worker_id`, `external_jobs` (die Jobs, die dieser Worker gerade rechnet; der Name stammt aus der Zeit vor den Prioritätenlisten, damit Automationen weiterlaufen)
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
