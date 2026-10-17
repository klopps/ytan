# YTAN – Wetterradar-Overlay mit Kurzfristvorhersage

Oct 17, 2026 · @Christoph

## Ziel und Umfang

YTAN bekommt wieder ein Regenradar direkt in der Karte, diesmal mit eigener Datenaufbereitung: Niederschlag der letzten 60 Minuten plus Vorhersage bis 2 Stunden für Deutschland, Dänemark und Schweden, nutzbar auch bei den in YTAN üblichen Zoomstufen 12 bis 16.

Der erste Versuch mit RainViewer (13.09.2026) wurde am 14.09.2026 wieder entfernt, weil die kostenlosen Kacheln nur bis Zoomstufe 7 Daten enthalten (done.md, „Regenradar-Layer“). Eigene Kacheln lösen genau dieses Problem. Der Windy-Link im Kontextmenü bleibt als Ergänzung bestehen.

- **Gebiet:** Deutschland, Dänemark, Schweden einschließlich Ostsee und Küstengewässer.
- **Zeitraum:** Beobachtung von −60 Minuten bis jetzt, Vorhersage bis +120 Minuten. Der Takt (5, 10 oder 15 Minuten) ergibt sich aus der gewählten Quelle.
- **Anzeige:** Kachel-Overlay über Google Maps mit Zeitleiste, Play/Pause, deutlicher Kennzeichnung der Prognose, Legende in mm/h und Quellenangabe.
- **Nicht im Umfang:** Blitze, Unwetterwarnungen, Niederschlagsart (Regen/Schnee). Finnland lässt sich später ohne Mehraufwand ergänzen, wenn die Quelle OPERA ist.

## Architektur

Ein Python-Job rechnet alles Aufwendige vorab, PHP liefert nur fertige Dateien aus, und das Frontend tauscht lediglich Kachel-URLs. So bleibt die bestehende PHP/JS-Struktur unverändert, und Python ist eine reine Batch-Komponente.

&#91;embedded content: Datenfluss · 6 Bausteine\]

Der Python-Job schreibt Kacheln nur bis Zoomstufe 9, das entspricht etwa der Auflösung der Radardaten (1 bis 2 km). Für die in YTAN üblichen Zoomstufen 12 bis 16 schneidet PHP den passenden Ausschnitt aus der Elternkachel und skaliert ihn hoch. Das spart Speicher und Rechenzeit, und die Bilder werden dadurch nicht schlechter, weil feinere Daten ohnehin nicht existieren.

In die Codebasis passt das so:

- **Python:** neues Verzeichnis `tools/radar/`, unabhängig von Composer und `vendor/`.
- **PHP:** neuer `RadarController` in `src/Http/Controllers/`, registriert in `src/App.php` neben `WeatherController`. Kein Datenbankzugriff, nur Dateien aus `storage/radar/`.
- **JS:** neues Modul `public/js/radar.js`, eingebunden wie die übrigen Module, mit Toggle im Drawer und Eintrag im Kartenkontextmenü (`registerMapContextMenuItem()`).
- **Service Worker:** keine Änderung nötig, solange die Kacheln unter `/api/` liegen.

## To-Do-Liste

Sechs Phasen, jede mit einem prüfbaren Ergebnis. Phase 0 entscheidet über Quelle und Ausführungsort und sollte vor jeder Zeile Code stehen.

### Phase 0: Klärung

- [x] Prüfen, ob auf dem Webhost Python 3.10+ und Cron verfügbar sind. Per SSH geprüft (2026-10-17, Host `www104`, Debian 13): Python 3.13.5 (`/usr/bin/python3`), `crontab` nutzbar. **Kein pip, kein `ensurepip`, kein C-Compiler**, `/tmp` ist `noexec`. Das geht trotzdem: `python3 -m venv --without-pip tools/radar/.venv` (unter `/home`, nicht `/tmp`), dann `get-pip.py` von `bootstrap.pypa.io` mit dem venv-Python ausführen und Pakete immer mit `python -m pip` aufrufen. Fertige Wheels (cp313, manylinux_2_28) gibt es für numpy 2.5.4, h5py 3.16.0, pyproj 3.8.0, pillow 13.0.0, scipy 1.18.1 und opencv-python-headless 5.0.0 (Probelauf mit `pip install --only-binary=:all: --dry-run`). **pysteps hat keine Wheels** (nur Quellpaket mit C-Erweiterungen) und lässt sich ohne Compiler nicht bauen. Ersatz für Phase 2: Zugfeld mit OpenCV-Optical-Flow (`cv2.calcOpticalFlowFarneback` oder DIS, pysteps' Lucas-Kanade nutzt intern ebenfalls OpenCV), Verschiebung als semi-Lagrange-Extrapolation mit `scipy.ndimage.map_coordinates`. Das ist für eine reine Extrapolation ohne Ensemble gleichwertig und überschaubar. Ressourcen: 12 Kerne, 12 GB RAM (geteilt), keine CPU-Zeitgrenze (`ulimit -t` unlimited).
- [x] OPERA-Echtzeitzugang klären, siehe "Ergebnis der Recherche" unten. OPERA ist frei nutzbar (CC BY 4.0, ohne Schlüssel), damit entfallen die nationalen Quellen.
- [x] DMI-Klausel geklärt: DMI Open Data steht unter CC BY 4.0, Bearbeiten ist ausdrücklich erlaubt ("Adapt - remix, transform, and build upon the material"); Pflicht ist nur die Quellenangabe mit Lizenzlink und dem Hinweis, dass die Daten verändert wurden. Eine Klausel "Daten dürfen nicht verändert werden" gibt es in den Nutzungsbedingungen nicht. Wird mit OPERA ohnehin nicht gebraucht.
- [x] GD und Inodes geprüft (2026-10-17): GD ist fest eingebaut (`--enable-gd`, bundled 2.1.0, PNG-Unterstützung; kein `gd.so`, also in allen SAPIs des Builds vorhanden), PHP 8.3.35, Imagick ist auch da. Speicherquota 59.390 MB, davon 15.757 MB belegt; **keine Begrenzung der Dateianzahl** (Quota "files" 135k belegt, Limit 0), das Dateisystem `/home` hat 56 Mio. freie Inodes. 100.000 Kacheln sind also kein Problem. Der Server erreicht den OPERA-S3-Speicher (HTTP 200 in 0,25 s).
- [x] Zielgebiet als Bounding Box: 47-70° N, 5-25° O (wie in "Speicherbedarf" angenommen; deckt DE, DK, SE samt Ostsee ab). In Phase 1 prüfen, ob das OPERA-Raster Nordschweden bis 69° N vollständig enthält (das Raster ist in LAEA um 55° N/10° O, die Nordecken liegen bei rund 67° N).

**Ergebnis der Recherche (2026-10-17):**

- **Zugang:** EUMETNET "Open Radar Data" (ORD) über MeteoGate, OGC-EDR-Schnittstelle `https://api.meteogate.eu/eu-eumetnet-weather-radar/collections/observations/locations/0-20010-0-OPERA?datetime=VON%2FBIS&f=CoverageJSON&standard_name=RATE&format=ODIM&method=comp`. Die Antwort ist klein (CoverageJSON für einen Punkt) und enthält unter `links` die Download-Links der Dateien, z. B. `https://s3.waw3-1.cloudferro.com/openradar-24h/2026/10/17/OPERA/COMP/OPERA@20261017T0400@0@RATE.h5`. Die S3-Namen sind regelmäßig (`openradar-24h/YYYY/MM/DD/OPERA/COMP/OPERA@YYYYMMDDTHHMM@0@PRODUKT.h5`), der Job kann sie auch direkt bilden. Rollierender 24-Stunden-Speicher, Archiv ab 2012.
- **Ohne Schlüssel**, aber begrenzt: anonym 200 Anfragen pro Stunde (Header `X-RateLimit-*`); einen API-Schlüssel gibt es im MeteoGate Developer Portal. Ein Lauf alle 5 Minuten braucht 12 Anfragen pro Stunde, also unkritisch.
- **Lizenz:** CC BY 4.0, Quellenangabe "EUMETNET OPERA" mit Lizenzlink und Hinweis auf die Bearbeitung (Einfärben, Reprojektion, Prognose). Passt in die bestehende `data_source`-Tabelle bzw. die About-Seite.
- **Produkte, live gemessen am 17.10.2026 um 04:17 UTC:**
  - `DBZH` (maximale Reflektivität, dBZ): alle 5 Minuten, neuester Frame 04:10, also rund 7 Minuten Verzögerung, ~2,5 MB pro Datei.
  - `RATE` (Regenrate): alle 15 Minuten, neuester Frame 04:00, also rund 17 Minuten Verzögerung, ~1,4 MB pro Datei.
  - `ACRR` (1-Stunden-Summe): für den Zweck nicht nötig.
  - Format ODIM-HDF5 (auch als Cloud-optimiertes GeoTIFF angeboten), Raster laut Schnittstelle 1900 x 2200 Punkte zu 2 km, die Dateien selbst haben inzwischen 3800 x 4400 zu 1 km, Projektion `+proj=laea +lat_0=55.0 +lon_0=10.0 +x_0=1950000.0 +y_0=-2100000.0 +units=m +ellps=WGS84`.
- **Empfehlung:** `DBZH` im 5-Minuten-Takt als Quelle für Anzeige und Nowcast (aktueller als `RATE`, pysteps braucht ohnehin dichte Frames), Umrechnung dBZ in mm/h mit Marshall-Palmer (Z = 200 R^1,6). Damit gilt im Speicherbedarf die Zeile "5 Minuten, 60 Minuten" (37 Frames, ca. 100-300 MB). Alternative bei Speicherknappheit: nur jeden dritten Frame als Kachel schreiben (15 Minuten), den Nowcast aber aus allen rechnen. Der Download pro Lauf ist ~2,5 MB, also ~720 MB pro Tag Eingangsverkehr.
- **Nationale Quellen** (DWD RADOLAN/RV als HDF5 auf opendata.dwd.de, SMHI, DMI mit API-Schlüssel) werden nicht gebraucht; sie bleiben Rückfall, falls ORD ausfällt.

Ergebnis: Quelle ist entschieden (OPERA über ORD), Ausführungsort ist der Webhost. Phase 0 ist abgeschlossen. Änderung gegenüber dem Plan: kein pysteps (siehe oben), stattdessen OpenCV + SciPy; `requirements.txt` in Phase 1 entsprechend: numpy, h5py, pyproj, pillow, scipy, opencv-python-headless.

### Stand der Umsetzung (2026-10-17)

Phase 1 bis 4 sind gebaut und lokal getestet (Code nicht deployt). Abweichungen vom Plan:

- **Kacheln bis Zoomstufe 8, nicht 9.** OPERA liefert inzwischen 1 km (nicht 2 km); ein Mosaik in Zoomstufe 9 hätte ~120 Mio. Pixel pro Frame. Zoomstufe 8 (~350 m pro Pixel bei 55° N) ist schon 3-fach feiner als die Daten. Ab Zoomstufe 9 vergrößert PHP die Zoom-8-Kachel (Nearest Neighbour, damit die Farben der Legende bleiben; geglättet wird schon beim Umprojizieren in Python, bilinear).
- **Overzoom-Cache im Frame-Verzeichnis** (`frames/{id}/{z}/...`) statt eines eigenen Caches: der Python-Job löscht ihn mit dem Frame, `bin/cleanup-file-caches.php` braucht keine Ergänzung.
- **Kein pysteps** (keine Wheels, kein Compiler auf dem Host): Zugfeld per OpenCV-DIS-Optical-Flow aus den letzten 3 Frames, gewichtet geglättet (nur Regenflächen zählen, sonst Mittelwert), semi-Lagrange-Rückwärtsadvektion in 5-Minuten-Schritten.
- **Frame-IDs** prüft PHP per Muster (`o` + 12 Ziffern, `f` + 12 Ziffern + `-NNN`) und auf ein vorhandenes Verzeichnis, nicht gegen das aktuelle Manifest: ein Client mit einem 5 Minuten alten Manifest soll seine Frames noch laden können (der Job lässt Frames 10 Minuten länger liegen).
- **Vorladen:** nur der aktuelle und die nächsten 3 Frames sind Kartenebenen (Umschalten per Deckkraft), sonst hielte ein Handy ~37 x 15 dekodierte Kacheln.
- **Verifikation** schreibt der Job laufend nach `storage/radar/verify.csv` (CSI bei 1 mm/h für +15/+30/+60 Minuten, Nowcast gegen Persistenz). Erste Werte: +15 Min. 0,68 gegen 0,51, +30 Min. 0,58 gegen 0,42. Über mehrere Regentage auswerten steht noch aus.

Offen: Deploy, venv und Cron auf dem Server, Test in der Android-App, Verifikation über einige Regentage, Speicherbedarf nach einer Woche.

### Phase 1: Python-Pipeline für Beobachtungsdaten

- [x] Neues Verzeichnis `tools/radar/` mit `requirements.txt` (numpy, h5py, pyproj, rasterio oder GDAL, Pillow, pysteps).
- [x] Abruf der aktuellen Composites und der letzten 60 Minuten.
- [x] Dekodierung: „kein Radar“ (nodata) und „kein Niederschlag“ (undetect) sauber trennen. Reflektivität (dBZ) bei Bedarf in Regenrate (mm/h) umrechnen.
- [x] Reprojektion nach Web Mercator (EPSG:3857) und Zuschnitt auf das Zielgebiet.
- [x] Farbtabelle in mm/h-Stufen festlegen, transparent unterhalb eines Schwellwerts gegen Rauschen.
- [x] PNG-Kacheln mit Farbpalette für Zoomstufe 5 bis 9 schreiben, leere Kacheln weglassen, dazu `manifest.json` mit Frame-IDs, Zeitstempeln und Typ (Beobachtung oder Prognose).
- [x] Atomar veröffentlichen: erst in ein temporäres Verzeichnis schreiben, dann umbenennen. So sieht die App nie halbfertige Frames.
- [x] Beobachtungs-Frames älter als 60 Minuten löschen, Prognose-Frames bei jedem Lauf ersetzen.

Ergebnis: Ein Lauf ist fertig, bevor der nächste startet. Stichproben an Kieler Förde und Øresund liegen deckungsgleich mit der Küstenlinie in Google Maps.

### Phase 2: Vorhersage (Nowcast)

- [x] Mit pysteps aus den letzten 3 bis 4 Frames das Zugfeld schätzen (Optical Flow).
- [x] Niederschlagsfeld entlang des Zugfelds bis +120 Minuten verschieben (Extrapolation, kein Ensemble).
- [x] Prognose-Frames im Manifest als `forecast` markieren, mit Laufzeit und Bezugszeitpunkt.
- [ ] Einfache Verifikation: Prognose gegen die später eintreffende Beobachtung vergleichen, über einige Regentage.

Ergebnis: Der Lauf einschließlich Nowcast passt in den Takt. Die Verifikation zeigt, bis zu welcher Vorlaufzeit die Prognose brauchbar ist.

### Phase 3: PHP-Backend

- [x] `RadarController` mit Routen in `src/App.php`: `GET /api/v1/radar/manifest` und `GET /api/v1/radar/tiles/{frame}/{z}/{x}/{y}.png`.
- [x] Overzoom: Ab Zoomstufe 10 die passende Elternkachel aus Zoomstufe 9 laden, den Ausschnitt mit GD hochskalieren und in `storage` zwischenspeichern. Die Aufräumlogik in `bin/cleanup-file-caches.php` ergänzen.
- [x] Eingaben streng prüfen: nur Ziffern für z, x, y; Frame-ID nur aus dem aktuellen Manifest (wie der Pfad-Fix in `WsiRenderer`).
- [x] Außerhalb des Gebiets eine leere transparente Kachel liefern.
- [x] Cache-Header: Kacheln eines Frames sind unveränderlich und dürfen lange gecacht werden, das Manifest nur kurz.
- [x] Pfad unter `/api/` belassen: `templates/sw.php` speichert sonst jede `.png` dauerhaft im Shell-Cache (cache-first).
- [x] `docs/API.md` ergänzen, PHPUnit-Tests für Pfadprüfung und Overzoom.

Ergebnis: Bei Zoomstufe 14 kommt eine sinnvoll skalierte Kachel zurück, ungültige Pfade werden mit 404 abgewiesen.

### Phase 4: Frontend

- [x] `public/js/radar.js` neu anlegen. Als Vorlage dient die am 14.09.2026 entfernte Version aus der Git-Historie (ImageMapType, Zeitleiste, Play/Pause).
- [x] Toggle „Regenradar“ im Drawer wie beim WSI-Toggle, dazu ein Eintrag im Kartenkontextmenü neben „Regenradar auf Windy öffnen“.
- [x] Zeitleiste mit sichtbarer Grenze zwischen Beobachtung und Prognose, Anzeige „Stand: hh:mm“.
- [x] Frames vorladen, damit der Wechsel nicht flackert. Animation pausieren, wenn die App im Hintergrund ist.
- [x] Legende in mm/h und Quellenangabe, Texte auf Deutsch und Englisch.
- [x] Offline den Toggle deaktivieren und einen Hinweis zeigen.
- [ ] In der Android-App (Capacitor) und im Dark Mode prüfen. (Dark Mode und Mobil-/Desktop-Browser geprüft, die App noch nicht.)

Ergebnis: Am Smartphone bei Zoomstufe 14 sichtbar und flüssig animiert.

### Phase 5: Betrieb und Dokumentation

- [ ] Cron-Eintrag für den Python-Job, Logdatei, Warnung, wenn das Manifest älter als 30 Minuten ist. Die App zeigt dann „Radardaten veraltet“.
- [ ] Speicherbedarf der Kacheln nach einer Woche Betrieb messen.
- [x] Anwenderdoku `docs/user/de/10-wetter.md` und `README.md` ergänzen.
- [x] Datenquellen in der Info-Seite bzw. im Impressum nennen.

## Risiken und offene Fragen

Das größte Risiko liegt nicht im Code, sondern in zwei Klärungen: ob Python auf dem Webhost läuft und ob OPERA in Echtzeit frei nutzbar ist.

| Risiko | Auswirkung | Gegenmaßnahme |
| --- | --- | --- |
| Kein Python oder Cron auf dem Webhost | Pipeline kann nicht neben PHP laufen | Job auf einem eigenen Rechner ausführen, Kacheln per SSH hochladen |
| OPERA in Echtzeit nicht frei verfügbar | Keine einheitliche Quelle für alle drei Länder | DWD, SMHI und DMI einzeln anbinden und zu einem Mosaik zusammenführen (deutlich mehr Aufwand, siehe Schätzung) |
| DMI verbietet Veränderung der Daten | Dänemark fehlt bei nationalen Quellen | OPERA deckt Dänemark mit ab; sonst bei DMI nachfragen |
| Prognose wird nach 60 bis 120 Minuten unzuverlässig | Nutzer verlassen sich auf eine schwache Vorhersage | Prognose deutlich kennzeichnen, Ergebnis der Verifikation aus Phase 2 berücksichtigen |
| Verzögerung der Quelle | „Jetzt“ ist in Wahrheit 10 bis 15 Minuten alt | „Stand: hh:mm“ in der Zeitleiste anzeigen |
| Datenvolumen am Smartphone | Langsames Laden unterwegs | Nur sichtbare Kacheln laden, Frames schrittweise vorladen |
| Service Worker cached PNGs dauerhaft | Veraltete Radarbilder | Kacheln ausschließlich unter `/api/` ausliefern |

Offene Fragen:

- Wie oft soll der Job laufen? Alle 5 Minuten bringt aktuellere Bilder, alle 15 Minuten passt zum OPERA-Regenraten-Takt und spart Rechenzeit.
- Soll das Radar auch für nicht angemeldete Nutzer sichtbar sein? Die Wetterdaten sind es bisher.

## Speicherbedarf

Mit 15-Minuten-Takt, 60 Minuten Rückblick und ohne leere Kacheln bleibt der Bedarf unter 150 MB. Kritischer als der Speicherplatz kann die Anzahl der Dateien sein.

Annahmen: Das Zielgebiet (rund 47–70° N, 5–25° O) braucht für Zoomstufe 5 bis 9 genau 2.657 Kacheln pro Frame, davon 1.943 auf Zoomstufe 9. Eine Regenkachel als PNG mit Farbpalette hat geschätzt 5 KB. Regen bedeckt an einem normalen Regentag etwa 20 % der Fläche, bei großflächigem Regen bis 60 %. Leere Kacheln werden nicht gespeichert.

| Takt | Rückblick | Frames (inkl. 2 h Prognose) | Normaler Regentag | Großflächiger Regen |
| --- | --- | --- | --- | --- |
| 15 Minuten | 60 Minuten | 13 | ca. 35 MB | ca. 105 MB |
| 15 Minuten | 3 Stunden | 21 | ca. 60 MB | ca. 170 MB |
| 5 Minuten | 60 Minuten | 37 | ca. 100 MB | ca. 300 MB |
| 5 Minuten | 3 Stunden | 61 | ca. 165 MB | ca. 490 MB |

- **Dateianzahl:** Würden auch leere Kacheln geschrieben, wären es beim 5-Minuten-Takt bis zu 160.000 Dateien. Viele Webhoster begrenzen die Anzahl der Dateien (Inodes). Ohne leere Kacheln sind es typisch 30.000 bis 100.000.
- **Overzoom-Cache:** Die von PHP hochskalierten Kacheln ab Zoomstufe 10 entstehen nur dort, wo jemand hinschaut. Bei wenigen Nutzern sind das einige MB. Sie werden mit ihrem Frame gelöscht.
- **Kurzzeitige Spitze:** Beim atomaren Austausch liegen die Prognose-Frames kurz doppelt vor.
- **Datenvolumen am Smartphone:** Eine Animation bei Zoomstufe 14 lädt grob 1 bis 5 MB.

Die Kachelgröße ist geschätzt. Die echten Werte misst Phase 5 nach einer Woche Betrieb.

## Aufwandsschätzung

Mit OPERA als einziger Quelle rund 12 bis 19 Personentage für einen Entwickler, der die YTAN-Codebasis kennt. Mit drei nationalen Quellen kommen etwa 5 bis 8 Tage hinzu.

| Phase | Aufwand (Personentage) | Treiber |
| --- | --- | --- |
| 0: Klärung | 1–2 | Antworten von Hoster und Datenanbietern |
| 1: Python-Pipeline | 3–5 | Dekodierung und Reprojektion, Abgleich mit der Karte |
| 2: Nowcast | 2–3 | pysteps ist fertig, Aufwand steckt in Verifikation |
| 3: PHP-Backend | 2–3 | Overzoom mit GD, Tests |
| 4: Frontend | 3–4 | Teilweise aus der alten radar.js übernehmbar |
| 5: Betrieb und Doku | 1–2 | Monitoring, Anwenderdoku |
| Zuschlag nationale Quellen | 5–8 | Drei Formate, Mosaik an den Landesgrenzen |

Das sind grobe Schätzungen ohne Puffer für Rückfragen beim Hoster oder bei EUMETNET.

## Quellen

- [OPERA-Daten im ODIM-HDF5-Format (earth2studio)](https://nvidia.github.io/earth2studio/main/modules/generated/data/analysis/OPERA/)
- [OPERA Max Reflectivity Product Sheet](https://eumetnet.eu/wp-content/uploads/2024/06/OPERA_Max-Reflectivity_Product-Sheet_Ed-2.0.pdf)
- [NIMBUS-Datenblatt (Regenrate, Akkumulation)](https://eumetnet.eu/wp-content/uploads/2024/06/NIMBUS_datasheet_composites_1.0_13062024.pdf)
- [pysteps (Geoscientific Model Development)](https://gmd.copernicus.org/articles/12/4185/2019/)
- [SMHI Open Data Radar API](https://opendata.smhi.se/apidocs/radar)
- [DMI Open Data FAQ](https://www.dmi.dk/friedata/dokumentation/faq)
- [YTAN-Repository](https://github.com/klopps/ytan)
