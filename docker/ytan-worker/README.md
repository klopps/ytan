# YTAN-Worker in Docker (ohne Home Assistant)

Derselbe Worker wie das Home-Assistant-Add-on (`ha-addon/ytan-worker/`), für ein normales Linux-System im Heimnetz, z. B. `ytanworker` (192.168.178.12, Debian 13, 4 GB RAM, 128 GB SSD). Er rechnet die Jobs (Wind, Regenradar), die ihm die Prioritätenlisten unter `Admin > Worker` auf dem YTAN-Server geben, und lädt die Ergebnisse hoch. Nur der Worker verbindet sich – per SSH/rsync zum Server. Es ist keine Portfreigabe nötig.

## Voraussetzungen auf dem Worker-System

1. Debian (aktuell 13 „Trixie“) mit SSH-Server, feste IP (DHCP-Reservierung im Router).
2. Docker aus dem offiziellen Repository (Anleitung „Install Docker Engine on Debian“ auf `docs.docker.com`), der Benutzer in der Gruppe `docker`.
3. Swap von 2 GB als Sicherung (`fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile`, Eintrag in `/etc/fstab`).
4. Auf dem Entwicklungsrechner: der OpenSSH-Client und ein Schlüssel-Login zum Worker-System (`ssh-copy-id benutzer@192.168.178.12`), in `.env` die Zeilen `YTAN_WORKER_HOST=192.168.178.12` und `YTAN_WORKER_USER=benutzer`.

## Einrichten

1. Auf dem YTAN-Server unter `Admin > Worker` den Namen anlegen (z. B. `ytanworker`). Das legt auch die Verzeichnisse `storage/workers/` und `storage/logs/worker/` an, die der erste Upload braucht.
2. Auf dem Worker-System `~/ytan-worker/worker.env` anlegen (Vorlage `worker.env.example`) und `WORKER_ID` auf den Namen setzen. Diese Datei enthält ein Geheimnis (AROME-Schlüssel) und bleibt nur dort; `bin\publish-worker.bat` überschreibt sie nie.
3. `bin\publish-worker.bat` ausführen: kopiert `tools/` und `docker/ytan-worker/`, baut das Image auf dem Worker-System und startet den Container.
4. Das Protokoll (`docker logs ytan-worker`) zeigt beim Start eine Zeile mit `command="/usr/bin/rrsync ...`. Diese Zeile ab `command=` auf dem YTAN-Server in `~/.ssh/authorized_keys` des SSH-Benutzers eintragen (Verzeichnis `.ssh` mit Rechten 700, Datei 600). Der Schlüssel kann damit **nur** rsync unterhalb von `storage/`.
5. Unter `Admin > Worker` den Worker in die Listen der Jobs aufnehmen, die er rechnen soll (jede Änderung dort wird sofort gespeichert).

**Ein eigener Schlüssel je Worker.** Der Schlüssel liegt in `~/ytan-worker/data/ssh/`; nie auf ein zweites System kopieren. Zum sofortigen Sperren die Zeile dieses Schlüssels in `authorized_keys` löschen. Der Server kann die Schlüssel nicht unterscheiden (rrsync): Jeder Worker kann alles unter `storage/` lesen und schreiben. Die Seite `Admin > Worker` erklärt das Restrisiko.

## Betrieb

- Protokoll: `docker logs -f ytan-worker` (jede Zeile: Zeit, `[Quelle]`, Text) oder auf dem Server `Admin > Logs` (Quelle „YTAN-Worker ytanworker“).
- Daten: `~/ytan-worker/data/storage` (Job-Ausgabe, wird jederzeit neu berechnet), `data/ssh` (Schlüssel, `known_hosts`) und die Datei `data/storage/.worker-instance` (die Kennung dieses Systems: Löscht man sie, hält der Server das System für ein neues, und es ist bis zu 15 Minuten im Namenskonflikt, außer man gibt den Namen unter `Admin > Worker` mit „Instanz zurücksetzen“ frei).
- Neue Version: wieder `bin\publish-worker.bat`.
- RAM: Das Image rechnet immer nur einen Job. Der Radar braucht bis ~1 GB (`max_mosaic_pixels` unter `Admin > Radar`: über etwa 300 Mio. wird es auf 4 GB knapp), der Wind ähnlich viel. Die Grenze des Containers (`WORKER_MEM_LIMIT`, Standard 3 GB) schützt das System.
- Mehrere Worker: Jeder bekommt einen eigenen Namen, eine eigene `worker.env` und einen eigenen SSH-Schlüssel. Wer was rechnet, entscheiden die Listen.
