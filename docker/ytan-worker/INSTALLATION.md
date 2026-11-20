# YTAN-Worker als Docker-Container auf Debian installieren

Diese Anleitung führt Schritt für Schritt vom leeren Debian-Rechner im Heimnetz bis zu einem laufenden YTAN-Worker, der Wind und Regenradar für den YTAN-Server rechnet. Das Beispiel ist der Rechner `ytanworker` (192.168.178.12, IPU675 mit 4 GB RAM und 128 GB SSD). Alles gilt genauso für jedes andere Debian-System, auch für einen zweiten oder dritten Worker.

## Überblick: Wie die Teile zusammenarbeiten

- Der **Worker** ist ein Docker-Container auf einem Rechner in deinem Heimnetz. Er rechnet die Jobs (Wind, Regenradar), die ihm der YTAN-Server zuteilt, und lädt die Ergebnisse hoch.
- Der **YTAN-Server** (Produktion) rechnet Jobs nur noch selbst, wenn er in der Liste eines Jobs steht. Welcher Worker welchen Job rechnet, legst du im Admin-Bereich unter **Admin > Worker** fest (Prioritätenliste je Job, bei Ausfall springt der nächste Eintrag ein).
- Nur der **Worker verbindet sich zum Server**, per SSH/rsync. Der Server verbindet sich nie zum Worker. Du brauchst auf deinem Router also **keine Portfreigabe**.
- Die **Software** bringst du von deinem **Entwicklungsrechner** (Windows) auf den Worker: Das Skript `bin\publish-worker.bat` kopiert den Programmcode und baut den Container. Die **Verwaltung** (Namen, Listen, Zustand, Logs) machst du im Admin-Bereich.

Es gibt also drei Orte, an denen du etwas tust:

| Ort | Wofür |
|---|---|
| **Worker-System** (Debian) | Docker, Swap, Konfigurationsdatei `worker.env` |
| **Entwicklungsrechner** (Windows) | SSH-Schlüssel zum Worker, `bin\publish-worker.bat` |
| **YTAN-Server** | `Admin > Worker` (Namen, Listen) und die Datei `~/.ssh/authorized_keys` |

## Voraussetzungen

- Ein Debian-Rechner mit Netzwerkanschluss. Empfohlen: **Debian 13 „Trixie“**, 64 Bit (amd64). Mindestens 4 GB RAM, 20 GB freier Plattenplatz (das Image und die Job-Daten brauchen einige GB).
- Ein Benutzer auf diesem Rechner mit `sudo`-Recht (bei der Installation angelegt). In dieser Anleitung heißt er `ytan`, ersetze ihn durch deinen Benutzer.
- Der YTAN-Server ist auf dem Stand mit der Worker-Verwaltung (Seite `Admin > Worker` ist vorhanden). Die Datenbank-Migrationen 081 bis 083 sind eingespielt.
- Du hast Admin-Rechte im YTAN und Zugriff auf die Datei `~/.ssh/authorized_keys` des SSH-Benutzers auf dem YTAN-Server.
- Dein Entwicklungsrechner steht im selben Netz wie der Worker und hat den **OpenSSH-Client** (in Windows 10/11 enthalten; sonst unter Einstellungen > Apps > Optionale Features nachinstallieren).

---

## Teil 1: Debian vorbereiten

### 1.1 Debian installieren (falls noch nicht geschehen)

1. Lade das Netzwerk-Installationsabbild (`netinst`, amd64) von `debian.org` herunter (Seite „Debian installieren“) und schreibe es mit Rufus oder balenaEtcher auf einen USB-Stick.
2. Schließe Monitor und Tastatur an den Rechner, stecke den Stick ein und starte den Rechner. Wähle im BIOS oder Boot-Menü (meist `Entf`, `F2` oder `F11`) den Stick aus.
3. Ein LAN-Kabel muss stecken, der Installer lädt Pakete nach.
4. Im Installer: Sprache und Tastaturbelegung wählen, einen Rechnernamen vergeben (zum Beispiel `ytanworker`), das **Root-Passwort leer lassen** (dann bekommt dein Benutzer `sudo`) und einen Benutzer anlegen.
5. Partitionierung: „Geführt – gesamte Platte verwenden“ mit allem auf einer Partition.
6. Softwareauswahl: **nur** „SSH-Server“ und „Standard-Systemwerkzeuge“ ankreuzen, keine Desktop-Umgebung.
7. GRUB auf die Platte installieren, den Stick abziehen und neu starten.

> **Achtung:** Die Installation überschreibt die ganze Platte. Läuft auf dem Rechner noch ein altes System (zum Beispiel OPNsense), sind dessen Daten danach weg. Sichere vorher dessen Konfiguration.

### 1.2 Feste IP-Adresse vergeben

Der Entwicklungsrechner findet den Worker über seine Adresse. Vergib ihm deshalb im Router eine **feste IP** (DHCP-Reservierung, bei einer Fritz!Box unter Heimnetz > Netzwerk > Gerät > „Diesem Netzwerkgerät immer die gleiche IPv4-Adresse zuweisen“). In dieser Anleitung ist es `192.168.178.12`.

### 1.3 Per SSH anmelden

Ab hier brauchst du Monitor und Tastatur am Worker nicht mehr. Melde dich von deinem Entwicklungsrechner an (PowerShell):

```powershell
ssh ytan@192.168.178.12
```

Beim ersten Mal fragt SSH, ob du dem Host-Schlüssel traust: mit `yes` bestätigen. Alle Befehle in Teil 1 und 2 gibst du auf dem Worker ein.

### 1.4 System aktualisieren

```bash
sudo apt update && sudo apt full-upgrade -y
```

### 1.5 Zeitzone und Uhrzeit prüfen

Die Zeitstempel in den Logs sind Ortszeit, und der Worker bewertet Berichte nach ihrer Uhrzeit. Prüfe, dass die Uhr stimmt und synchronisiert ist:

```bash
timedatectl
```

In der Ausgabe soll `System clock synchronized: yes` stehen. Die Zeitzone stellst du bei Bedarf mit `sudo timedatectl set-timezone Europe/Berlin` ein.

### 1.6 Swap einrichten (2 GB als Sicherung)

Der Rechner hat 4 GB RAM. Ein Job (Radar oder Wind) braucht bis etwa 1 GB. Der Swap fängt Spitzen ab, damit nicht gleich ein Prozess beendet wird:

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

Kontrolle mit `free -m`: Die Zeile `Swap:` zeigt rund 2047 MB.

---

## Teil 2: Docker installieren

Installiere Docker aus dem **offiziellen Docker-Repository** (die Pakete von Debian sind älter). Maßgeblich ist die Anleitung „Install Docker Engine on Debian“ auf `docs.docker.com`. Der Ablauf (Stand dieser Anleitung):

```bash
sudo apt install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

Deinen Benutzer in die Gruppe `docker` aufnehmen, damit er ohne `sudo` mit Docker arbeiten darf, und danach **abmelden und wieder anmelden**:

```bash
sudo usermod -aG docker $USER
exit
```

Melde dich wieder an (`ssh ytan@192.168.178.12`) und prüfe:

```bash
docker run --rm hello-world
docker compose version
```

Bei `hello-world` erscheint „Hello from Docker!“, und `docker compose version` zeigt eine Versionsnummer. Kommt `permission denied while trying to connect to the Docker daemon socket`, hast du dich nach `usermod` nicht neu angemeldet.

---

## Teil 3: Auf dem YTAN-Server den Worker anlegen

1. Öffne im Browser `Admin > Worker` (Admin-Bereich des YTAN, Menüpunkt „Worker“).
2. Trage unter „Registrierte Worker“ ein:
   - **Name:** `ytanworker` (1 bis 32 Zeichen: `a-z`, `0-9`, `-`; nicht `server`). Der Name ist die **Identität** des Workers und muss später genau so in `worker.env` stehen. Notiere ihn dir.
   - **Anzeigename:** zum Beispiel „IPU675 im Keller“.
   - **Notiz:** zum Beispiel `192.168.178.12`.
3. Klicke auf **Worker anlegen**. Die Seite speichert sofort (nur Anzeigename und Notiz eines schon angelegten Workers ändert man später mit dem Speichern-Knopf an dessen Karte).

Er rechnet noch nichts: Ein Worker rechnet erst, wenn er in der Liste eines Jobs steht (Teil 8).

---

## Teil 4: SSH-Zugang vom Entwicklungsrechner zum Worker

Das Skript `bin\publish-worker.bat` meldet sich ohne Passwort per Schlüssel am Worker an. Das richtest du einmal ein. Auf Windows gibt es kein `ssh-copy-id`, deshalb von Hand. Alle Befehle in diesem Teil gibst du in **PowerShell auf dem Entwicklungsrechner** ein.

1. Schlüssel erzeugen, falls noch keiner existiert (Fragen mit Enter bestätigen, eine Passphrase ist optional):

   ```powershell
   if (-not (Test-Path $env:USERPROFILE\.ssh\id_ed25519.pub)) { ssh-keygen -t ed25519 }
   ```

2. Öffentlichen Schlüssel auf den Worker kopieren (fragt einmal nach dem Passwort des Benutzers `ytan`):

   ```powershell
   type $env:USERPROFILE\.ssh\id_ed25519.pub | ssh ytan@192.168.178.12 "mkdir -p ~/.ssh && chmod 700 ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
   ```

3. Prüfen, dass es ohne Passwort geht:

   ```powershell
   ssh -o BatchMode=yes ytan@192.168.178.12 echo ok
   ```

   Es muss `ok` erscheinen. Bei `Permission denied (publickey)` ist der Schlüssel nicht eingetragen, dann Schritt 2 wiederholen.

4. Trage Adresse und Benutzer in die Datei `.env` im YTAN-Projektordner auf dem Entwicklungsrechner ein:

   ```
   YTAN_WORKER_HOST=192.168.178.12
   YTAN_WORKER_USER=ytan
   ```

   Das sind nur Adresse und Benutzername, keine Geheimnisse. Alternativ setzt du sie vor dem Aufruf des Skripts als Umgebungsvariablen (`set YTAN_WORKER_HOST=...`), dann nimmt das Skript diese Werte.

---

## Teil 5: Konfigurationsdatei `worker.env` auf dem Worker anlegen

Die Datei `~/ytan-worker/worker.env` liegt **nur auf dem Worker**. Sie enthält ein Geheimnis (den AROME-Schlüssel), wird nie ins Git übernommen, und das Veröffentlichungsskript überschreibt sie nie.

Auf dem Entwicklungsrechner (PowerShell, im YTAN-Projektordner):

```powershell
ssh ytan@192.168.178.12 "mkdir -p ytan-worker"
scp docker\ytan-worker\worker.env.example ytan@192.168.178.12:ytan-worker/worker.env
ssh -t ytan@192.168.178.12 "nano ytan-worker/worker.env"
```

In `nano` die Werte anpassen. Speichern mit `Strg+O` und Enter, beenden mit `Strg+X`.

| Variable | Bedeutung |
|---|---|
| `YTAN_SERVER_HOST` | Adresse des YTAN-Servers (SSH-Ziel), zum Beispiel `steindorff.de` |
| `YTAN_SERVER_USER` | SSH-Benutzer auf dem YTAN-Server |
| `YTAN_SERVER_STORAGE` | absoluter Pfad des Ordners `storage/` auf dem YTAN-Server (wird nur für die angezeigte `authorized_keys`-Zeile gebraucht) |
| `WORKER_ID` | **der Name aus Teil 3**, genau gleich geschrieben, zum Beispiel `ytanworker` |
| `WORKER_JOBS` | was dieser Rechner kann, kommagetrennt (`radar,wind`). **Leer = alle Jobs.** Lass einen Job weg, für den der Rechner zu klein ist. |
| `WORKER_ADDRESS` | eine Adresse, die `Admin > Worker` anzeigt (nur Anzeige) |
| `WORKER_HOST_NAME` | Rechnername in der Anzeige. Ohne diese Angabe steht die Container-Kennung dort, und die ändert sich bei jedem Neubau. |
| `WORKER_THREADS` | Threads/Prozesse je Job (Standard 2) |
| `TICK_MINUTES` | Minuten zwischen zwei Takten (Standard 5) |
| `AROME_API_KEY` | Schlüssel für die Météo-France-AROME-Schnittstelle (Wind über Frankreich). Leer = der Windjob läuft ohne AROME. Der Schlüssel muss eine **lange Laufzeit** haben (siehe Fehlerbehebung). |
| `TZ` | Zeitzone der Zeitstempel in den Logs, Standard `Europe/Berlin` |
| `WORKER_MEM_LIMIT` | Speichergrenze des Containers, Standard `3g` (bei 4 GB RAM). Schützt den Rechner vor einem außer Kontrolle geratenen Job. |

> **Wichtig:** `WORKER_ID` muss mit dem Namen in `Admin > Worker` übereinstimmen. Stehen dort `ytanworker` und hier `ytanworker1`, erscheint der Worker als „unbekannt“ und rechnet nichts.

---

## Teil 6: Container bauen und starten

Auf dem **Entwicklungsrechner** im YTAN-Projektordner:

```powershell
bin\publish-worker.bat
```

Das Skript macht Folgendes:

1. Es prüft, ob der Schlüssel-Login funktioniert, ob Docker nutzbar ist und ob `~/ytan-worker/worker.env` existiert. Bei einem Fehler nennt es den Grund und was zu tun ist.
2. Es kopiert die Ordner `tools/` und `docker/ytan-worker/` per `tar` durch SSH nach `~/ytan-worker` auf dem Worker (`worker.env` und `data/` bleiben unberührt).
3. Es baut das Docker-Image auf dem Worker und startet den Container (`docker compose up -d --build`).

Der **erste Build dauert einige Minuten**, weil alle Python-Pakete (numpy, scipy, opencv und andere) geladen werden. Spätere Aktualisierungen gehen schneller.

Ergebnis auf dem Worker prüfen:

```bash
docker ps
```

In der Liste steht `ytan-worker` mit dem Status `Up …`.

---

## Teil 7: Den SSH-Schlüssel des Workers auf dem YTAN-Server freischalten

Der Container erzeugt beim ersten Start einen **eigenen SSH-Schlüssel** für die Verbindung zum YTAN-Server. Dieser Schlüssel muss dort eingetragen sein, sonst meldet der Worker `Permission denied`.

1. Zeige das Protokoll des Containers (auf dem Worker oder per `ssh`):

   ```bash
   docker logs ytan-worker
   ```

2. Suche die Zeile, die nach Zeitstempel und `[addon]` mit `command="/usr/bin/rrsync ...` beginnt. Sie sieht so aus (gekürzt):

   ```
   command="/usr/bin/rrsync /home/www/.../storage",restrict ssh-ed25519 AAAA... ytan-worker@ytanworker
   ```

3. Kopiere diese Zeile **ab `command=`** bis zum Ende, als **eine einzige Zeile**.
4. Melde dich auf dem **YTAN-Server** an, öffne `~/.ssh/authorized_keys` des SSH-Benutzers und füge die Zeile **zusätzlich** unten an. Vorhandene Zeilen (zum Beispiel die des Home-Assistant-Workers) bleiben stehen. Die Rechte müssen stimmen: Ordner `~/.ssh` mit `700`, Datei `authorized_keys` mit `600`.

Was die Zeile bewirkt: Der Schlüssel darf **nur rsync** und **nur unterhalb des YTAN-Ordners `storage/`**. Er bekommt keine Shell und keinen Zugriff auf andere Webseiten des Servers.

Ein Neustart des Containers ist danach nicht nötig. Beim nächsten Takt (höchstens 5 Minuten) klappt die Verbindung von selbst.

---

## Teil 8: Den Worker in die Listen eintragen

1. Öffne `Admin > Worker`. Der Worker steht bei den registrierten Workern, und der Zustand sollte „letzter Kontakt vor … min“ zeigen. Steht er stattdessen unter „Unbekannte Worker“, ist der Name in `worker.env` anders geschrieben als der angelegte (siehe Fehlerbehebung).
2. Gehe zur Karte des Jobs, den der Worker rechnen soll (**Wind** und/oder **Regenradar**), wähle im Auswahlfeld den Worker aus und klicke auf **Zur Liste**.
3. Bringe die Einträge mit den Pfeilen in die gewünschte **Reihenfolge**. Es rechnet der **erste aktivierte Eintrag, der sich meldet und den Job kann**. Meldet er sich nicht mehr, springt der nächste ein.
4. Der Server (`Dieser Server (Cron)`) darf in der Liste stehen. Setze ihn **ans Ende**, dann ist er der Notnagel, falls alle Worker ausfallen. Für den Radar bedenke: Er braucht auf dem Server bis zu 1 GB Speicher.
5. Mit dem Schalter „aktiv“ nimmst du einen Eintrag zeitweise aus der Wahl, ohne ihn zu löschen.

Änderungen an den Listen werden sofort gespeichert. Anzeigename und Notiz eines Workers speicherst du mit dem Knopf **Speichern** am Ende seiner Karte (oder mit Enter im Feld). Das Entfernen eines Workers und das Herausnehmen eines Eintrags aus einer Liste fragen vorher nach. Die Zustände neben den Einträgen bedeuten:

| Zustand | Bedeutung |
|---|---|
| **arbeitet** | Dieser Eintrag rechnet den Job jetzt. |
| **bereit** | Er meldet sich und kann den Job, ist aber nicht der erste. |
| **meldet sich nicht** | Sein letzter Bericht ist älter als 15 Minuten. |
| **kann den Job nicht** | Er meldet den Job nicht unter seinen Fähigkeiten (`WORKER_JOBS`). |
| **noch nie gemeldet** | Von diesem Namen gibt es noch keinen Bericht. |
| **aus** | Der Eintrag ist deaktiviert. |

Beim nächsten Takt rechnet der Worker. Ein Wind-Lauf braucht je nach Gebiet bis zu mehreren Minuten, der Radar läuft alle 5 Minuten.

---

## Teil 9: Prüfen, ob alles läuft

Auf dem **Worker**:

```bash
docker logs -f ytan-worker
```

Jede Zeile beginnt mit Datum, Uhrzeit und der Quelle in eckigen Klammern: `[addon]` (der Container selbst), `[worker]` (der Takt), `[wind]`, `[radar]` (die Jobs). Ein erfolgreicher Takt **ohne Job schreibt nichts**, nur Fehler und Jobläufe stehen im Protokoll. Ein Job zeigt zum Beispiel `wind: exit 0 in 41.2 s, upload ok`.

Den Bericht, den der Worker hochlädt, kannst du ansehen:

```bash
docker exec ytan-worker cat /data/storage/outbox/worker-status.json
```

Im **Admin-Bereich**:

- `Admin > Worker`: letzter Kontakt, Fähigkeiten, laufender Job und wer gerade rechnet („Rechnet jetzt: …“).
- `Admin > Logs`: Die Logs des Workers erscheinen mit der Herkunft „YTAN-Worker ytanworker“ (der Worker lädt sie bei jedem Takt hoch).
- `Admin` (Übersicht), Karte „Probleme“: meldet zum Beispiel, wenn niemand einen Job rechnet oder ein Vertreter einspringt.

---

## Betrieb

### Konfiguration ändern

`worker.env` bearbeiten und den Container neu erzeugen:

```bash
nano ~/ytan-worker/worker.env
cd ~/ytan-worker
docker compose -f docker/ytan-worker/docker-compose.yml --env-file worker.env up -d --force-recreate
```

Wer welchen Job rechnet, änderst du **nicht** hier, sondern in `Admin > Worker`.

### Neue Version des Workers einspielen

Auf dem Entwicklungsrechner einfach wieder `bin\publish-worker.bat` ausführen. Die Daten (`~/ytan-worker/data`) und `worker.env` bleiben erhalten.

### Starten, stoppen, neu starten

```bash
docker stop ytan-worker
docker start ytan-worker
docker restart ytan-worker
```

Der Container hat `restart: unless-stopped`: Er startet nach einem Neustart des Rechners von selbst wieder, außer du hast ihn mit `docker stop` angehalten.

### Wo liegen die Daten?

| Pfad auf dem Worker | Inhalt |
|---|---|
| `~/ytan-worker/worker.env` | Konfiguration mit Geheimnis |
| `~/ytan-worker/data/storage/` | Job-Ausgabe, wird jederzeit neu berechnet |
| `~/ytan-worker/data/ssh/` | der SSH-Schlüssel des Workers und `known_hosts` |
| `~/ytan-worker/data/storage/.worker-instance` | die Kennung dieses Systems (siehe „Namenskonflikt“) |

Sichere `data/ssh/`, wenn du den Worker später auf ein neues Gerät umziehen willst, ohne die `authorized_keys`-Zeile zu ändern. Alles andere wird neu berechnet.

### Plattenplatz aufräumen

Nach vielen Neubauten sammeln sich alte Docker-Images:

```bash
docker image prune -f
docker system df
```

---

## Fehlerbehebung

| Meldung / Beobachtung | Ursache | Lösung |
|---|---|---|
| `Permission denied (publickey,password)` im Log des Containers | Die `authorized_keys`-Zeile des Workers fehlt auf dem YTAN-Server oder ist zerrissen. | Teil 7 prüfen: eine einzige Zeile, Rechte `700`/`600`. |
| `workers/ could not be fetched from the server` | Auf dem Server fehlt der Ordner `storage/workers/`. | Einmal `Admin > Worker` öffnen (legt ihn an) oder den Server neu deployen (Migration 082). |
| `the server's config.json has no job_workers` | Der Server ist älter als der Worker. | Den Server deployen. |
| Worker steht unter „Unbekannte Worker“ | `WORKER_ID` in `worker.env` ≠ angelegter Name. | Den Namen angleichen (entweder in `worker.env` und Container neu erzeugen, oder den Namen unter „Unbekannte Worker“ mit **Anlegen** registrieren). |
| `the name … is used by another system` | Ein zweites System meldet sich mit demselben Namen (oder das System wurde ersetzt, dann ist die Kennung neu). | Jedem System einen **eigenen Namen** geben. Nach einem Gerätewechsel unter `Admin > Worker` beim Namen **Instanz zurücksetzen** wählen. |
| Worker meldet sich, rechnet aber nichts | Er steht in keiner Liste, ist dort deaktiviert, oder `WORKER_JOBS` enthält den Job nicht. | Teil 8 prüfen. |
| Zustand „meldet sich nicht“ | Der letzte Bericht ist älter als 15 Minuten. | `docker ps` (läuft der Container?) und `docker logs ytan-worker`. |
| `AROME API key rejected (HTTP 401)` im `[wind]`-Log | Der Schlüssel ist abgelaufen oder falsch. Der Windjob läuft ohne AROME weiter. | Im Portal von Météo-France einen neuen Schlüssel mit **langer Laufzeit** erzeugen, in `worker.env` eintragen, Container neu erzeugen. |
| Der Container wird beendet, `docker inspect ytan-worker` zeigt `OOMKilled` | Ein Job braucht mehr Speicher als `WORKER_MEM_LIMIT`. | Beim Radar im Admin die maximale Mosaikgröße (`max_mosaic_pixels`) verringern, `WORKER_MEM_LIMIT` erhöhen (bei 4 GB RAM höchstens `3g`), oder `WORKER_JOBS` auf `wind` beschränken. |
| `permission denied while trying to connect to the Docker daemon socket` | Der Benutzer ist nicht in der Gruppe `docker`. | `sudo usermod -aG docker $USER`, dann abmelden und neu anmelden. |
| `bin\publish-worker.bat`: „No key login“ | Der Schlüssel-Login zum Worker fehlt. | Teil 4 wiederholen. |
| `bin\publish-worker.bat`: „worker.env is missing“ | Teil 5 fehlt. | `worker.env` anlegen. |
| Zeitstempel im Log um eine oder zwei Stunden verschoben | Zeitzone des Containers. | `TZ=Europe/Berlin` in `worker.env`, Container neu erzeugen. |
| `REMOTE HOST IDENTIFICATION HAS CHANGED` | Der Host-Schlüssel des YTAN-Servers hat sich geändert (Umzug, Neuinstallation). | Die Datei `~/ytan-worker/data/ssh/known_hosts` löschen, der Worker lernt den Schlüssel beim nächsten Takt neu. |

---

## Sicherheit: was du wissen solltest

- Der SSH-Schlüssel des Workers darf nur rsync und nur im Ordner `storage/` des YTAN-Servers. Der Server kann die Schlüssel verschiedener Worker aber **nicht unterscheiden**: Jeder Worker kann alles unter `storage/` lesen und schreiben (auch hochgeladene Fotos), und der Name im Bericht ist seine eigene Angabe.
- Ein gestohlener Schlüssel oder ein gehacktes Worker-System könnte deshalb Wind- und Radardaten verfälschen oder Dateien dort löschen. Er könnte **keinen Code auf dem Server ausführen** und nicht an die Datenbank.
- Darum: **ein eigener Schlüssel je Worker** (den Schlüssel nie auf ein zweites System kopieren), Worker nur auf Rechnern im eigenen Heimnetz, und die Datei `worker.env` nicht weitergeben.
- **Einen Worker sofort sperren:** Lösche seine Zeile in `~/.ssh/authorized_keys` auf dem YTAN-Server. Das wirkt sofort. Danach kannst du ihn in `Admin > Worker` aus den Listen nehmen.

---

## Einen weiteren Worker einrichten

Für jedes weitere System wiederholst du die Teile 1 bis 8 mit **eigenen Werten**:

- ein **eigener Name** (zum Beispiel `ytanworker2`) in `Admin > Worker` und in `worker.env`,
- eine **eigene `worker.env`** und damit ein eigener SSH-Schlüssel,
- vor dem Aufruf von `bin\publish-worker.bat` die Variablen `YTAN_WORKER_HOST` und `YTAN_WORKER_USER` auf das neue System setzen (`set YTAN_WORKER_HOST=...`), damit nicht die Werte aus der `.env` gelten.

Welcher Worker was rechnet, entscheiden allein die Listen in `Admin > Worker`.

## Den Worker entfernen

1. In `Admin > Worker` den Worker aus allen Listen nehmen und über **Entfernen** löschen.
2. Die Zeile des Workers aus `~/.ssh/authorized_keys` auf dem YTAN-Server löschen.
3. Auf dem Worker: Container und Daten entfernen:

   ```bash
   cd ~/ytan-worker
   docker compose -f docker/ytan-worker/docker-compose.yml --env-file worker.env down
   cd ~ && rm -rf ytan-worker
   docker image rm ytan-worker:local
   ```
