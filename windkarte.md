# YTAN – Windkarte mit animierten Partikeln

Oct 18, 2026 · @Christoph

## Ziel und Umfang

YTAN bekommt eine Windebene in der Karte: animierte Partikel, die mit dem Wind ziehen (je stärker der Wind, desto schneller), darunter eine farbige Fläche der Windstärke mit geringer Deckkraft. Dazu eine Zeitleiste über die Vorhersagestunden.

- **Gebiet:** dieselben Regionen wie beim Regenradar (`/admin/radar`, `storage/radar/config.json`). Keine eigene Auswahl.
- **Zeitraum:** stündlich von der aktuellen Stunde bis zum eingestellten Horizont (Standard 48 h).
- **Auflösung:** ~2,2 km im ICON-D2-Gebiet (Deutschland und Nachbarländer), sonst ~6,5 km (ICON-EU).
- **Ressourcen:** Rechenzeit am Server gering, Datenvolumen am Handy klein, Animation sparsam (Canvas 2D, siehe Phase 4).
- **Nicht im Umfang:** Wind in der Höhe, Wellen, Strömung, eigene Modellrechnung.

## Datenquellen (geprüft 2026-10-18)

Beide Modelle kommen vom DWD (opendata.dwd.de, frei, Quellenangabe "Deutscher Wetterdienst" nötig). Variablen: `u_10m`, `v_10m`, optional `vmax_10m` (Böen).

| Modell | Raster | Gebiet | Läufe | Schritte | Datei pro Feld | Verfügbar nach Laufbeginn |
| --- | --- | --- | --- | --- | --- | --- |
| ICON-D2 | 0,02°, 1215 × 746 | 43,18–58,08° N, −3,94–20,34° O | alle 3 h | 0–48 h stündlich | ~1,04 MB (bz2) | ~45–80 min |
| ICON-EU | 0,0625°, 1377 × 657 | 29,5–70,5° N, −23,5–62,5° O | alle 3 h | 00/06/12/18: 0–78 h stündlich, dann 3-stündlich bis 120 h (93 Dateien); übrige Läufe 0–51 h (52 Dateien) | ~1,1 MB (bz2) | ~2 h 45 min |

Dateinamen (regelmäßig, der Job bildet sie selbst):

- `https://opendata.dwd.de/weather/nwp/icon-d2/grib/HH/u_10m/icon-d2_germany_regular-lat-lon_single-level_YYYYMMDDHH_SSS_2d_u_10m.grib2.bz2`
- `https://opendata.dwd.de/weather/nwp/icon-eu/grib/HH/u_10m/icon-eu_europe_regular-lat-lon_single-level_YYYYMMDDHH_SSS_U_10M.grib2.bz2`

**Lücke:** ICON-EU endet bei 70,5° N und −23,5° O. Nördlich davon (Nordkap, Norwegen `no` bis 71,2°, Russland `ru` bis 72°) und im äußersten Westen Islands (`is` ab −24,6°) gibt es keine Daten. Diese Randstreifen bleiben vorerst leer (Entscheidung 2026-10-18).

## GRIB2 lesen ohne eccodes

Gemessen 2026-10-18 an echten Dateien:

- ICON-D2: **einfache Packung** (Data Representation Template 5.0), 16 Bit, mit Bitmap (das gedrehte D2-Gebiet lässt im Rechteck ~151.500 Punkte leer).
- ICON-EU: **CCSDS-Kompression** (Template 5.42), 16 Bit, ohne Bitmap. Flags 14, Blockgröße 32, RSI 128.
- `eccodes` (Python) bringt seine Bibliothek nur für Linux mit (`eccodeslib`, kein Windows-Wheel), `pygrib` hat keine Wheels. Auf dem Server ginge eccodes, lokal unter Windows nicht.

Lösung: **eigener kleiner Decoder** (~80 Zeilen) mit numpy und `imagecodecs` (`aec_decode` für CCSDS; abi3-Wheels für manylinux_2_28 und Windows). Prototyp im Scratchpad dekodiert beide Dateien korrekt (u-Werte −14,7 … +18,6 m/s). Wichtig dabei: `aec_decode` meldet bei genau passendem Ausgabepuffer "output buffer too small", also größer anlegen und abschneiden.

Zeit pro Feld (lokal gemessen): ICON-EU 57 ms bz2 + 27 ms CCSDS, ICON-D2 64 ms bz2 + 9 ms.

## Architektur

Wie beim Regenradar: ein Python-Job rechnet vorab, PHP liefert nur Dateien aus, das Frontend zeichnet selbst.

- **Python:** `tools/wind/` mit `wind_job.py` und Paket `ytanwind/`. Nutzt das vorhandene venv `tools/radar/.venv` (ein venv auf dem Server ist genug), `requirements.txt` von `tools/radar/` um `imagecodecs` ergänzen.
- **Ausgabe:** `storage/wind/` (aus allen Deploy-Skripten ausschließen, wie `storage/radar`).
- **PHP:** `WindController` + `WindDataService`, Routen unter `/api/v1/wind/` (unter `/api/`, sonst cached `sw.php`).
- **JS:** `public/js/wind.js`, eingebunden nach `radar.js`.
- **Regionen:** der Job liest `regions` aus `storage/radar/config.json` (nur lesen). Fehlt die Datei, gelten die Standardregionen aus `ytanradar/settings.py`.

### Datenformat: Blöcke in drei Stufen

Der Client lädt nur die Blöcke, die er für den sichtbaren Ausschnitt braucht. Ein Block ist ein festes Lat/Lon-Raster, an einem globalen Gitter ausgerichtet (Ursprung −90° / −180°), damit Blocknummern aus Koordinaten berechenbar sind.

| Stufe | Quelle | Auflösung | Block | Verwendet ab Zoom |
| --- | --- | --- | --- | --- |
| L0 | ICON-EU, 4 × 4 gemittelt | 0,25° | ganzes Gebiet in einer Datei | 0–5 |
| L1 | ICON-EU | 0,0625° | 128 Punkte = 8° | 6–8 (ab 9 außerhalb D2) |
| L2 | ICON-D2, Lücken aus ICON-EU aufgefüllt | 0,02° | 128 Punkte = 2,56° | 9+ im D2-Gebiet |

- **Gitter passt:** die Modellpunkte liegen auf Vielfachen der Auflösung (29,5 / 0,0625 = 472, −3,94 / 0,02 = −197), also ist Punktindex = `round((lon + 180) / res)`.
- **Überlappung:** jeder Block hat 129 × 129 Punkte (eine Zeile/Spalte des Nachbarn), damit der Client bilinear interpolieren kann, ohne zwei Blöcke anzufassen.
- **Kodierung:** pro Punkt 1 Byte u, 1 Byte v (optional 1 Byte Böe), verschachtelt. Feste Skala −40 … +40 m/s auf 0 … 254 (Schritt 0,31 m/s), 255 = keine Daten (außerhalb der Regionen oder des Modells). Böe 0 … 60 m/s.
- **Datei:** roh, mit gzip vorab komprimiert (`.bin.gz`). PHP liefert sie mit `Content-Encoding: gzip`, der Browser entpackt selbst, JS bekommt ein `ArrayBuffer`. Kein PNG: das spart den Umweg über einen Canvas und schließt Farbraum-Umrechnungen des Browsers aus.
- **Maske:** Blöcke, deren Punkte alle außerhalb der Regionen liegen, werden nicht geschrieben.
- **D2-Rand:** die äußersten ~0,25° des D2-Gebiets werden verworfen (Randeffekte des Modells) und wie die Bitmap-Lücken bilinear aus ICON-EU gefüllt. So hat jeder L2-Block keine Löcher, und die Naht D2/EU fällt kaum auf.

### Verzeichnis und Manifest

```
storage/wind/
  config.json              (nicht nötig: Regionen kommen aus storage/radar/config.json)
  manifest.json
  status.json
  .lock
  runs/{runId}/            runId = Zeit der Veröffentlichung, z. B. 202610181015
    L0/{hhh}.bin.gz        hhh = Gültigkeitszeit als Stundenindex im Manifest
    L1/{hhh}/{bx}_{by}.bin.gz
    L2/{hhh}/{bx}_{by}.bin.gz
```

`manifest.json`:

```json
{
  "run": "202610181015",
  "sources": {"eu": "2026101806", "d2": "2026101809"},
  "hours": ["2026-10-18T10:00Z", "2026-10-18T11:00Z", "..."],
  "scale": {"uv": [-40, 40], "gust": [0, 60], "nodata": 255},
  "channels": 2,
  "levels": {
    "L0": {"res": 0.25, "south": 32.0, "west": -23.5, "rows": 155, "cols": 275},
    "L1": {"res": 0.0625, "size": 129, "blocks": ["19_15", "..."]},
    "L2": {"res": 0.02, "size": 129, "blocks": ["69_51", "..."]}
  },
  "regions_hash": "..."
}
```

Ein Lauf wird erst in `runs/{runId}.tmp/` geschrieben, dann umbenannt, dann das Manifest ersetzt (atomar wie beim Radar). Alte Läufe löscht der Job erst 30 Minuten nach dem Ersetzen, damit Clients mit dem alten Manifest ihre Blöcke noch laden können.

## Rechenzeit, Speicher, Verkehr (Schätzung aus den Messungen oben)

Für ganz Europa (alle Länder als Regionen), Horizont 48 h, ohne Böen:

| Posten | Pro Lauf | Pro Tag (8 Läufe) |
| --- | --- | --- |
| Download | EU 98 × 1,1 MB + D2 98 × 1,04 MB ≈ 210 MB | ~1,7 GB |
| Dekodieren | 196 Felder × ~80 ms ≈ 16 s | ~2 min |
| Blöcke schreiben + gzip | ~6.000 kleine Dateien, ~10 s | – |
| Speicher | L1 ~60 Blöcke + L2 ~60 Blöcke × 49 h × ~20 KB + L0 ≈ 120 MB | beim Austausch kurz doppelt |
| RAM | < 200 MB (ein Feld = 3,6 MB als float32) | – |

Mit den Standardregionen (DE/DK/SE) etwa ein Fünftel davon beim Speicher, Download und Dekodieren bleiben gleich (der DWD schneidet nicht aus). Mit Böen +50 %. Verkehr halbieren: nur jeden zweiten Modelllauf verarbeiten (Admin-Einstellung "alle 6 h").

Am Handy: ein Block L2 hat ~20 KB. Bei Zoom 10–12 braucht die Ansicht 1–4 Blöcke pro Stunde, also unter 100 KB. Zoom 6: 2–6 L1-Blöcke.

## Phase 1: Python-Job

- [x] `tools/wind/ytanwind/grib2.py`: Decoder für Templates 5.0 und 5.42, Bitmap (Abschnitt 6), Gitterdefinition 3.0 (Lat/Lon regulär). Zahlen mit Vorzeichen-Betrag-Kodierung (E, D) richtig lesen. Alles andere (andere Templates, mehrere Felder pro Datei) mit klarer Fehlermeldung ablehnen.
- [x] `ytanwind/dwd.py`: Dateinamen bilden, neuesten **vollständigen** Lauf finden (HEAD auf die Datei des letzten benötigten Schritts für u und v; fehlt sie, den Lauf davor nehmen). ICON-EU bei Horizont > 51 h nur 00/06/12/18-Läufe. Download mit Wiederholung bei 5xx, identifizierender User-Agent, bis zu 4 Downloads parallel (ThreadPool).
- [x] Nur benötigte Schritte laden: Gültigkeitszeit ≥ aktuelle Stunde − 1 h und ≤ Horizont. ICON-EU nach 78 h 3-stündlich: Zwischenstunden linear interpolieren oder weglassen (Entscheidung: weglassen, Manifest-`hours` hat dann Lücken, die Zeitleiste zeigt sie).
- [x] `ytanwind/blocks.py`: Ausschnitt auf das Rechteck der Regionen (+ 1 Block Rand), Maske außerhalb der Regionen, L0 (4 × 4 Mittel von u und v, nicht von Betrag/Richtung), L1, L2 (D2 mit EU-Füllung, Rand verwerfen), Kodierung uint8, gzip, nur nicht-leere Blöcke.
- [x] `ytanwind/job.py`: Ablauf wie `ytanradar/job.py`: Lock (`storage/wind/.lock`, gleiche `Lock`-Klasse), `status.json` (letzter Lauf, Ergebnis, Modellläufe, Download/Dekodieren/Schreiben in Sekunden, Bytes, Größe von `storage/wind`), Veröffentlichung per Umbenennen, Aufräumen.
- [x] Neu rechnen nur, wenn ein neuerer D2- oder EU-Lauf vorliegt oder sich `regions_hash` geändert hat; sonst nur aufräumen und Status schreiben (Ergebnis `unchanged`). `--force` rechnet immer.
- [x] Gültigkeit: Stunden in der Vergangenheit fallen beim nächsten Lauf weg; das Manifest bleibt bis dahin gültig, der Client blendet vergangene Stunden aus.
- [x] `wind_job.py` (CLI, wie `radar_job.py`): `--storage`, `--force`, `-v`. Cron alle 15 Minuten.
- [x] Unit-Tests `tools/wind/tests/`: synthetische GRIB2-Nachricht mit einfacher Packung und eine mit CCSDS (mit `imagecodecs.aec_encode` erzeugt) gegen bekannte Werte; Blockgrenzen und Überlappung; Maske; L0-Mittelung; D2-Füllung; Kodierung an den Skalengrenzen.
- [x] Gegenprobe einmalig von Hand: u/v an 3 Punkten (Kieler Förde, Biskaya, Ostsee) gegen Open-Meteo mit `models=icon_d2` bzw. `icon_eu` für dieselbe Stunde.

Ergebnis: ein Lauf für ganz Europa dauert unter 2 Minuten (überwiegend Download), Blöcke und Manifest liegen in `storage/wind/`.

## Phase 2: PHP-Backend

- [x] `WindDataService`: `manifest()` (liest `manifest.json`, null wenn keins), `block(run, level, hour, bx, by)` (Pfad nur aus geprüften Teilen, Datei muss existieren, sonst `NotFoundException`).
- [x] `WindController`, registriert in `App::create()` per `$c->set()`:
  - `GET /api/v1/wind/manifest` → `Cache-Control: public, max-age=60`.
  - `GET /api/v1/wind/{run:[0-9]{12}}/{level:L[012]}/{hour:[0-9]{3}}/{block:[0-9]+_[0-9]+}.bin` (L0 ohne Block: eigene Route `.../L0/{hour}.bin`) → Datei unverändert ausliefern, `Content-Type: application/octet-stream`, `Content-Encoding: gzip`, `Cache-Control: public, max-age=86400, immutable`.
  - `CompressionMiddleware` darf diese Antwort nicht noch einmal packen (prüfen; Ausnahme wie bei `/watch/device/route` ergänzen, falls sie es täte).
- [x] Öffentlich wie Radar und Wetter, kein Login.
- [x] PHPUnit: Pfadprüfung (`..`, falsche Muster, fehlender Lauf → 404), Header, Manifest fehlt → `data: null`.
- [x] `docs/API.md` ergänzen.

## Phase 3: Admin

Zuerst als Karte "Wind" auf `/admin/radar` geplant, seit 2026-10-19 eine eigene Seite `/admin/wind` (Menüpunkt "Wind"); die Regionen bleiben beim Regenradar.

- [x] Einstellungen: Horizont (24/48/72/120 h, Standard 48), Modelllauf alle 3/6 h, Böen ja/nein (Standard nein), feine Stufe ICON-D2 ja/nein (Standard ja). Migration mit neuen Spalten in `app_settings`, `RadarConfig::writeJobConfig()` schreibt sie zusätzlich als `"wind": {...}` in `storage/radar/config.json` (eine Konfigurationsdatei für beide Jobs).
- [x] Status aus `storage/wind/status.json` anzeigen (wie die Radar-Statuskarte).
- [x] "Jetzt aktualisieren": `RadarJobRunner` um das Skript als Parameter erweitern (oder `WindJobRunner` daneben), Lock `storage/wind/.lock` prüfen, `POST /settings/wind/run`.
- [x] PHPUnit für Normalisierung der neuen Einstellungen und den Runner-Aufruf.

## Phase 4: Frontend (`public/js/wind.js`)

### Einbindung

- [x] Eintrag im Kartenkontextmenü (`registerMapContextMenuItem('wind', 'wind.context_menu.toggle_item', ...)`), Zustand `settings.detailwind` (gespeichert mit den übrigen Einstellungen, kein Drawer-Schalter, wie beim Radar).
- [x] Bedienfeld unten links wie `#radarControls`: Zeitleiste (Stunden), Anzeige "Mo 14:00", Legende, Quellenangabe "Deutscher Wetterdienst", Schließen-x. Offline gesperrt, Hinweis "veraltet", wenn der neueste Modelllauf älter als 9 h ist.
- [x] Wind und Radar schließen sich gegenseitig aus (ein Bedienfeld, ein Overlay; Einschalten des einen schaltet das andere aus).

### Daten im Client

- [x] Manifest laden, alle 15 Minuten neu prüfen; neuer `run` → Blöcke neu laden.
- [x] Stufe nach Zoom wählen (siehe Tabelle), pro Block prüfen, ob er im Manifest steht; L2 fehlt → L1.
- [x] Blöcke als `Uint8Array` in einer LRU-Map halten (höchstens ~60 Blöcke ≈ 2 MB), nächste Stunde vorladen, sobald die aktuelle steht.

### Bildschirmraster (das Herzstück der Sparsamkeit)

- [x] Bei jedem `idle` der Karte und bei Stundenwechsel: Raster mit Zellen von 8 × 8 px über den Canvas (plus 1 Zelle Rand).
- [x] Pro Zelle die Mitte in Lat/Lng umrechnen: einmal aus `map.getBounds()` und der Canvasgröße mit der Mercator-Formel (keine Projektion pro Partikel, kein `fromContainerPixelToLatLng` pro Zelle).
- [x] u/v bilinear aus dem passenden Block, als Bildschirmvektor (u, −v) speichern (Mercator ist winkeltreu, die Richtung stimmt). Arrays: `Float32Array` für vx, vy, Stärke; `NaN` für keine Daten.

### Farbfläche

- [x] Eigener Canvas unter den Partikeln, CSS-Deckkraft ~0,3, nur bei `idle` oder Stundenwechsel neu gezeichnet.
- [x] Ein Pixel pro Rasterzelle: Stärke (oder Böe, wenn vorhanden und gewählt) über eine Farbtabelle (256 Einträge, ruhig → blau, mäßig → grün, frisch → gelb, stark → orange/rot, Sturm → violett, Grenzen an Beaufort) in ein `ImageData`, mit `drawImage` und Glättung auf Canvasgröße vergrößert.

### Partikel

- [x] Canvas mit `devicePixelRatio` 1 (höchstens 1,5), im selben `OverlayView` über der Farbfläche.
- [x] Anzahl = Canvasfläche / 500, höchstens 2.000 (Handy) bzw. 4.000 (Desktop). Zustand in `Float32Array` (x, y, Alter).
- [x] Pro Frame: Spur ausblenden mit einem `fillRect` (`destination-in`, Alpha 0,92), dann alle Partikel in **einem** Pfad, **ein** `stroke()`. Versatz pro Frame = Vektor der Zelle × fester Faktor (z. B. 10 m/s ≈ 2,5 px pro Frame), unabhängig vom Zoom, gedeckelt.
- [x] Lebensdauer zufällig 60–120 Frames; Partikel in Zellen ohne Daten oder außerhalb des Canvas sofort neu setzen.
- [x] Linienfarbe nach Kartentyp: hell über Satellit/Hybrid, dunkel über der Straßenkarte und im Dark Mode passend.
- [x] 30 fps (jeden zweiten `requestAnimationFrame` auslassen).

### Ruhe-Zustände

- [x] Bei `dragstart`/`zoom_changed` Partikel-Canvas leeren und anhalten, Farbfläche ausblenden; bei `idle` Raster neu, weiter.
- [x] `visibilitychange` (Hintergrund, auch in der Capacitor-App) → anhalten.
- [x] Nach 60 s ohne Bedienung anhalten, Farbfläche bleibt, Partikel-Spuren verblassen; jede Kartenbewegung oder ein Tipp auf das Bedienfeld startet sie wieder.
- [x] Ein offenes Vollbild-Panel (`panelOpened()`) hält die Animation an.

### Sonstiges

- [x] Texte DE/EN (`wind.*`), Legende in m/s oder kn je nach `settings.unit`, zusätzlich Beaufort.
- [x] CSP: nur `data-on-*`, keine Inline-Skripte.
- [ ] Messung am Handy (Chrome DevTools, Performance): Zeit pro Frame bei 2.000 Partikeln, Zeit für den Raster-Neuaufbau. Ziel: < 3 ms pro Frame, < 30 ms Neuaufbau.
- [ ] Mobil zuerst prüfen (~390 × 660), dann Desktop, dann Android-App.
- [x] e2e: Kontextmenü → Wind an → beide Canvas vorhanden, keine Konsolenfehler (Fixture-Manifest mit einem Block im e2e-`storage`, oder Test überspringen, wenn kein Manifest da ist).

## Phase 5: Betrieb und Dokumentation

- [x] `tools/wind/README.md` (Einrichtung, Cron-Zeile, z. B. `*/15 * * * * .../tools/radar/.venv/bin/python .../tools/wind/wind_job.py >> .../storage/logs/wind.log 2>&1`).
- [x] `imagecodecs` auf dem Server ins venv installieren (`python -m pip install --only-binary=:all: imagecodecs`).
- [x] `storage/wind` aus allen Deploy-Skripten ausschließen.
- [x] `data_source`-Eintrag bzw. About-Seite: "Deutscher Wetterdienst (DWD), ICON-D2/ICON-EU", Lizenz CC BY 4.0 / GeoNutzV, Hinweis auf Bearbeitung.
- [x] Anwenderdoku `docs/user/de/10-wetter.md` um einen Abschnitt Wind ergänzen (Screenshot über `help.spec.js` nur, wenn das e2e-Fixture steht).
- [x] `CLAUDE.md` um einen Abschnitt zum Wind-Job ergänzen (wie beim Regenradar).
- [ ] Nach einer Woche: Laufzeit, Speicher und Verkehr aus `status.json` prüfen.

## Stand der Umsetzung (2026-10-18)

Phase 1 bis 5 sind gebaut und lokal getestet (28 Python-Tests, PHPUnit 711 Tests, e2e `wind.spec.js` 5 Tests). Abweichungen und Messwerte:

- **Echter Lauf** (8 Länder: be, de, dk, es, fr, it, nl, se; ICON-EU und ICON-D2 vom 18Z-Lauf): 194 Downloads, 211 MB, **24 s**, 29 MB Ergebnis (50 Stunden, 18 L1- und 52 L2-Blöcke). Mit Böen: 322 MB Download (+50 %), Böen im Mittel 8,3 m/s gegen 4,6 m/s mittleren Wind an der Testkachel, nie unter dem Mittelwind.
- **Gegenprobe** gegen Open-Meteo (`icon_d2`) an der Kieler Förde, 21 UTC: 3,5 m/s / 80° (eigene Daten, L2) gegen 3,69 m/s / 77° (Open-Meteo); der Unterschied liegt in der Byte-Stufung (0,31 m/s) und im Gitterpunkt.
- **Browser** (Chromium, 390 x 660 und 1400 x 800, Zoom 7/8/12): alle Stufen laden, keine Konsolenfehler. Zeit pro Frame 0,1-0,3 ms für die Partikelschleife (ohne Rasterisierung, nicht auf einem Handy gemessen). Offen: Messung am echten Handy.
- **Ausschnitt** der Karte: der Job schneidet auf EIN Rechteck um alle Regionen zu, 1° größer (`PAD_DEGREES`). Zuerst wurde auf die einzelnen Regionsrechtecke zugeschnitten (gemeldet 2026-10-19: leere Streifen am Bildschirmrand, dann große Löcher über dem Meer zwischen den Ländern, z. B. Nordsee westlich von Dänemark); das Rechteck hat innen keine Löcher. Kosten bei den lokalen 8 Ländern: 44 MB statt 29 MB, 26 s.
- **Vergangene Stunde:** das Fenster beginnt eine Stunde vor "jetzt"; die Zeitleiste öffnet bei der aktuellen Stunde.
- **"Modell"-Zeit** im Panel ist die Startzeit des ICON-EU-Laufs (nicht die Zeit der Veröffentlichung); "veraltet" ab 9 Stunden.
- **Böen:** nur die Farbfläche kann nach Böen gefärbt werden (Chip "Böen" im Panel, nur wenn die Daten sie haben); die Partikel zeigen immer den Mittelwind. ICON-D2 `vmax_10m` hat bei Stunde 0 keinen brauchbaren Wert, der Job nimmt dort den Mittelwind.
- **Gebrochener Zoom (Handy, 2026-10-19):** Nach dem Zoomen mit zwei Fingern zeichnet Google die Karte mit gebrochenem Zoom (z. B. 6,63), `map.getZoom()` und `map.getBounds()` rechnen aber mit dem gerundeten Zoom. Die Winddaten saßen dadurch gegen die Karte verschoben (auf den Screenshots um etwa 65 px entgegen der Karte). `wind.js` nimmt jetzt den Maßstab aus der Projektion und verankert am Kartenmittelpunkt; ein e2e-Test prüft Zoom 7,63 und 8,4.
- **Abschalten** in der Admin-Karte löscht das Manifest sofort (kein Manifest = "keine Winddaten"), damit die Ebene für alle verschwindet, auch bevor der Cron läuft.
- **Deckkraft der Farbfläche** (2026-10-19): zuerst global auf `/admin/wind` (Migration 048), seit 2026-10-19 pro Benutzer: Regler in "Einstellungen" (0-100 %, Standard 40 %, Cookie `ytan_settings`, `settings.windFillOpacity`); die Spalte `wind_fill_opacity` fiel mit Migration 051 weg.
- **Admin:** kein "workers"-Wert in der Oberfläche (feste 4 Threads, `wind.workers` in `config.json` ginge).
- **Kontextmenü-Symbol** `waves` (das Symbol `air` gibt es in der mitgelieferten Icon-Schrift nicht).
- **Nicht umgesetzt:** weiche Naht zwischen ICON-D2 und ICON-EU (harter Wechsel, der Rand von D2 wird 0,24° breit verworfen und aus EU gefüllt), Interpolation der 3-Stunden-Schritte nach +78 h (die Stunden fehlen in der Zeitleiste).

## Risiken

| Risiko | Auswirkung | Gegenmaßnahme |
| --- | --- | --- |
| DWD ändert Packung oder Dateinamen | Job bricht ab | Decoder lehnt Unbekanntes mit klarer Meldung ab, Status zeigt den Fehler, altes Manifest bleibt gültig |
| Lauf beim DWD unvollständig | Lücken in der Zeitleiste | nur vollständige Läufe verwenden (HEAD auf den letzten Schritt) |
| Naht zwischen D2 und EU sichtbar | Sprung in Farbe/Partikeln am D2-Rand | Rand verwerfen, mit EU füllen; falls nötig, auf 0,5° breitem Streifen linear überblenden |
| Animation frisst Akku | schlechte Erfahrung unterwegs | DPR 1, 30 fps, Auto-Stopp, Pause bei Bewegung und im Hintergrund |
| 1,7 GB/Tag eingehender Verkehr | Hoster-Limits | Einstellung "alle 6 h" halbiert ihn |

## Phase 6: Feinere Modelle außerhalb von ICON-D2

Stufe L2 bekommt weitere Quellen. Alle werden im Job auf dasselbe 0,02°-Gitter umgerechnet, damit der Client unverändert bleibt; wo sich Quellen überlappen, gilt eine feste Rangfolge (ICON-D2 vor MEPS vor AROME), Lücken füllt wie bisher ICON-EU.

- [ ] **MET Norway MEPS** (2,5 km, Skandinavien, Dänemark, Ostsee, Norddeutschland; CC BY 4.0): NetCDF über THREDDS, Ausschnitt nach Region und Variable schon beim Server (OPeNDAP/NCSS). Lambert-Gitter, die Längen/Breiten stehen in der Datei; Windkomponenten können gitterbezogen sein und müssen dann nach Nord/Ost gedreht werden. NetCDF4 ist HDF5, `h5py` ist schon im venv. Prüfen: Lauftakt, Verzögerung, Dateigröße des Ausschnitts.
- [ ] **Météo-France AROME** (Frankreich und Grenzraum, 0,01°/0,025°): Open Data von Météo-France, GRIB2. Prüfen: Zugang (Schlüssel?), Lizenz, Packung (der Decoder kennt bisher nur 5.0 und 5.42).
- [ ] Umrechnung auf 0,02° (bilinear, `scipy.ndimage.map_coordinates`, wie beim Radar), Rangfolge, Rand jeder Quelle verwerfen wie bei D2.
- [ ] Manifest: `sources` je Quelle mit Laufzeit; die Quellenangabe im Bedienfeld nennt alle verwendeten Modelle.
- [ ] Rechenzeit und Verkehr je Quelle in `status.json`; je Quelle in der Admin-Karte abschaltbar.

## Entscheidungen (2026-10-18)

- **Mittelwind** ist die Standardanzeige. Böen bleiben eine Admin-Einstellung (Standard aus).
- **Außerhalb von ICON-EU** (2026-10-19) liefert ICON global (13 km) den Wind, nur als Rückfall (EU/D2 gewinnen, wo sie Werte haben) und nur für die ersten 72 Stunden. Der DWD bietet es nur auf dem Ikosaeder-Gitter an (Gitter 3.101, 2.949.120 Zellen, ~3,5 MB je Feld, alle 6 h, Zellmitten in separaten CLAT/CLON-Dateien); der Job rechnet es per Nächster-Zelle-Index (KD-Baum, einmalig, in `storage/wind/icon-global-index.npy` zwischengespeichert) auf 0,25° um und schreibt es in dieselben Stufen L0/L1 (kein Client-Umbau). Gemessen: 72 h Australien 528 MB Download, 30 s, 2,5 MB Ausgabe; Vergleich mit ICON-EU über Deutschland: Korrelation 0,98, Bias 0,0 m/s, RMSE 0,6 m/s. Über dem Rest der Welt nur 13 km Auflösung, an Küsten gröber als die Karte vermuten lässt. Einstellung `wind.world` (Standard an) als Checkbox auf /admin/wind (Migration 049, `WindConfig`).
- **Feinere Modelle** außerhalb von ICON-D2 kommen später dazu (Phase 6).
- **Wind und Radar** schließen sich gegenseitig aus: ein Bedienfeld, ein Overlay; Einschalten des einen schaltet das andere aus.
