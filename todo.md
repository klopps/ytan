# Offene Punkte

## Regenradar mit Kurzfristvorhersage als Kartenebene

Eigenes Regenradar über Google Maps für DE, DK und SE: Beobachtung der letzten 60 Minuten plus Vorhersage bis +120 Minuten, nutzbar bei Zoomstufe 12-16 (Grund für den neuen Anlauf: RainViewer liefert kostenlos nur bis Zoomstufe 7, siehe done.md "Regenradar-Layer").

Aufbau: Python-Job (Cron) holt Radar-Composites (bevorzugt OPERA, sonst DWD/SMHI/DMI), projiziert nach Web Mercator, rechnet den Nowcast (OpenCV-Optical-Flow) und schreibt PNG-Kacheln Zoom 5-8 plus manifest.json nach storage/radar. Neuer RadarController liefert Manifest und Kacheln unter /api/v1/radar/ (nicht außerhalb von /api/, sonst cached sw.php die PNGs dauerhaft) und skaliert ab Zoomstufe 9 die Zoom-8-Kachel per GD hoch. Neues public/js/radar.js (Vorlage: entfernte Version aus der Git-Historie) mit Zeitleiste, Prognose-Kennzeichnung, Legende und Quellenangabe.

Phase 0 (2026-10-17): Quelle ist OPERA über EUMETNET Open Radar Data (CC BY 4.0, ohne Schlüssel, anonym 200 Anfragen/h), `DBZH` alle 5 Minuten mit ~7 Minuten Verzögerung, 1-km-Raster in LAEA (die Doku sagt noch 2 km); nationale Quellen entfallen. DMI hat keine "nicht verändern"-Klausel (CC BY 4.0). Der Job läuft auf dem Webhost; per SSH geprüft: Python 3.13.5 und Cron vorhanden, kein pip/Compiler (venv mit `--without-pip` + `get-pip.py` geht, alle Pakete als Wheels außer pysteps - der Nowcast wird daher mit OpenCV-Optical-Flow + SciPy gebaut), GD fest eingebaut, kein Inode-Limit.

Phase 1-4 (2026-10-17) gebaut und lokal getestet: `tools/radar/` (Python-Job, 13 Unit-Tests), `RadarController`/`RadarTileService` (PHPUnit), `public/js/radar.js` (Drawer-Schalter, Kontextmenü, Zeitleiste mit Prognose-Teil, Legende, Offline-Sperre, "veraltet"-Hinweis), Anwenderdoku, About-Seite, docs/API.md. Kacheln bis Zoomstufe 8 statt 9, darüber vergrößert PHP. Abweichungen und Messwerte in wetterradar.md, "Stand der Umsetzung".

Offen:
- Deployt (2026-10-17) auf test.pesr.org und ytan.pesr.org, venv + Cron (`*/5`, Log `storage/logs/radar.log`) auf beiden eingerichtet; der Test-Cron ist auskommentiert (seit 2026-10-17, entlastet den gemeinsamen Host; zum Testen die Zeile in `crontab -e` wieder aktivieren).
- **Laufzeit:** war auf dem Server ~220 s pro normalem Lauf, knapp am 5-Minuten-Takt. Seit 2026-10-17 rendert der Job die Frames parallel (`--workers N`, Standard 4, `Config.workers`): voller erster Lauf auf dem Server 324 s -> 110 s. Noch zu deployen; danach im `radar.log` die Laufzeit normaler Läufe prüfen und ggf. die Zahl der Worker anpassen.
- **Behoben 2026-10-18:** an der spanisch-französischen Grenze sprang der Niederschlag alle 5 Minuten hin und her - 8 der 10 spanischen Radare sind nur in jedem zweiten OPERA-Komposit (gemessen: Regenanteil Nordspanien 68 % / 31 % im Wechsel, 19 % ohne Daten). Der Job merkt sich jetzt die Radarliste jedes Komposits und füllt einem Frame, dem Radare des vorigen fehlen, deren Gebiet (+100 km) aus dem vorigen Frame, um einen Schritt weiterbewegt (`Job.field()`, `nowcast.fill_missing_radars()`); danach 73-74 % in jedem Frame. Gilt auch für Nowcast und Verifikation. Nachbesserung (erster lokaler Test flackerte weiter): Rohdaten von vor der Änderung haben keine Radarliste und werden jetzt neu geladen (sonst blieb die letzte Stunde ungefüllt), und der Job lädt einen Frame vor dem Verlauf mit, damit auch der älteste gefüllt werden kann; danach 56,6-57,6 % in allen 13 Frames (lokale Regionen BE/DE/DK/FR/NL/SE, Nordspanien liegt im FR-Rechteck). Zweite Nachbesserung: ob ein Frame neu gezeichnet wird, entschied nur die Version im Manifest - ein Lauf, der alte Frames nicht ersetzen konnte, hatte sie schon hochgesetzt, der nächste behielt die alten, ungefüllten Frames (lokal 13:40-14:10 weiter Flackern). Jetzt steht in jeder Frame-Markierung `.complete`, mit welcher Version und welchen Regionen er gezeichnet wurde (`Job.signature`), passt das nicht, wird er neu gezeichnet. Nach dem Deploy zeichnet der Job alle Frames einmal neu (`RENDER_VERSION` = 3). Lokal kann ein Job von der Konsole Frames nicht löschen, die ein über /admin/radar (Apache = SYSTEM) gestarteter Job geschrieben hat - der Job warnt jetzt im Log davor; auf dem Server laufen Webserver und Cron unter demselben Benutzer.
- In der Android-App testen.
- `storage/radar/verify.csv` über einige Regentage auswerten: bis zu welcher Vorlaufzeit schlägt der Nowcast die Persistenz? Ggf. die Prognose kürzer anbieten.
- Speicherbedarf nach einer Woche messen.
- Möglich: Overzoom ab Zoomstufe 13 wirkt blockig (1-km-Pixel), ggf. weicher vergrößern.

Ein detaillierter Plan steht in wetterradar.md.


## Windkarte mit animierten Partikeln

Windebene in der Karte: animierte Partikel (je stärker der Wind, desto schneller) plus farbige Fläche der Windstärke mit geringer Deckkraft, Zeitleiste über die Vorhersagestunden. Gebiet = die Regionen des Regenradars (`storage/radar/config.json`), keine eigene Auswahl.

Quellen (geprüft 2026-10-18): DWD ICON-D2 (0,02°, alle 3 h, +48 h, einfache GRIB2-Packung) und ICON-EU (0,0625°, alle 3 h, bis +120 h, CCSDS-komprimiert). eccodes hat kein Windows-Wheel, pygrib keine Wheels; ein eigener kleiner Decoder mit numpy + `imagecodecs` dekodiert beide (Prototyp: ~80 ms pro Feld). Python-Job `tools/wind/` schreibt Datenblöcke (u/v als uint8, gzip) in drei Stufen nach `storage/wind/`, `WindController` liefert sie unter `/api/v1/wind/`, `public/js/wind.js` zeichnet Farbfläche und Partikel per Canvas 2D (Bildschirmraster bei `idle`, DPR 1, 30 fps, ein `stroke()` pro Frame, Pause bei Bewegung/Hintergrund/nach 60 s).

Entschieden (2026-10-18): Mittelwind als Standard (Böen nur per Admin-Einstellung), nördlich 70,5° N vorerst leer, feinere Modelle (MEPS, AROME) später als Phase 6, Wind und Radar schließen sich gegenseitig aus.

Phase 1-5 (2026-10-18) gebaut und lokal getestet, Phase 6 (feinere Modelle) offen: `tools/wind/` (Python-Job mit eigenem GRIB2-Leser, 28 Unit-Tests; echter Lauf für 8 Länder: 24 s, 211 MB Download, 29 MB Ergebnis), `WindController`/`WindDataService`, Admin-Karte "Wind" auf /admin/radar (Migration 047), `public/js/wind.js` (Partikel + Farbfläche, Böen-Chip, Ausschluss mit dem Regenradar), e2e-Spec `wind.spec.js`, Anleitung (10-wetter.md), About-Seite, docs/API.md, CLAUDE.md. Messwerte und Abweichungen: windkarte.md.

Offen:
- Deploy, dann auf beiden Servern `imagecodecs` ins venv (`python -m pip install --only-binary=:all: imagecodecs`) und Cron (`*/15`, Log `storage/logs/wind.log`, Zeile in `tools/wind/README.md`) einrichten, Migration 047 läuft über `bin/migrate.php`.
- In der Android-App testen (Akku, Frame-Zeit bei 2.000 Partikeln, Geste Ziehen/Zoomen).
- Messung am echten Handy: Zeit pro Frame und für den Raster-Neuaufbau (Ziel < 3 ms / < 30 ms; im Desktop-Chromium: 0,1-0,3 ms pro Frame ohne Rasterisierung).
- Laufzeit, Speicher und Verkehr nach einer Woche aus `status.json` prüfen (Hochrechnung bei ganz Europa: ~1,7 GB Download/Tag, ~120 MB Speicher).
- Phase 6: MET Norway MEPS (2,5 km) und AROME als weitere L2-Quellen, siehe windkarte.md.
- Möglich: Naht D2/EU weich überblenden (jetzt harter Wechsel), Stunden zwischen den 3-h-Schritten von ICON-EU nach +78 h interpolieren, Böen auch im Stundenverlauf.

## Performance: offene Punkte aus der Analyse vom 2026-10-16

Kompression, Browser-Caching, WSI-Icons, Marker bei Bedarf, das doppelte Laden, die Prüfung in Produktion, gerundete Koordinaten, `data_sources` in der POI-Liste, Controller bei Bedarf und schnellere Routen-/Gebietslinien sind erledigt (done.md).

Offen:

- **OPcache in Produktion prüfen:** ohne OPcache kostet allein das Laden/Parsen der PHP-Dateien pro Request ~50 ms Autoload + ~45 ms `App::create()` (lokal gemessen, das lokale Apache-PHP hat OPcache aus: `;zend_extension=opcache` in `c:/dev/php8/php.ini`). Auf dem Server z. B. per `php -i | grep opcache.enable` bzw. für das Web-PHP über das Hosting-Panel/phpinfo prüfen; ggf. aktivieren.
- **Restliche Long Tasks beim Laden** (4x gedrosselt, nach dem Umbau): längster ~280 ms, zusammen ~2,2 s; größte eigene Posten `createRoutes()` ~210 ms (davon Glätten ~100 ms), `setAllPois()` ~170 ms, `updatePoiClustering()` ~130 ms (Clusterbildung bei niedrigem Zoom). Weitere Möglichkeit: `createRoutes()` in Zeitscheiben aufteilen - aufwendig, weil Code direkt danach `routePaths[i]` erwartet (`showRoutes()`, `redrawRoutes()`, Links mit `?route=`).
- Datenbank-Indizes reichen bei der aktuellen Datenmenge (1,8 k POIs); `public` hat keinen Index, `WHERE user_id = ? OR public = 1` liest die Tabelle ganz - erst bei deutlich mehr Daten relevant.

Nicht umsetzen:

- **POI-Beschreibungen erst bei Bedarf laden** (~160 KB roh in der Liste): erst beim Öffnen des InfoWindows per `GET /pois/{id}` holen. Vorher klären, was `description` aus der Liste sonst braucht (Bearbeiten-Formular, Suche, Offline-Cache - offline wäre die Beschreibung dann nicht da).

## Sicherheit: offene Reste aus dem Audit vom 2026-10-16

Die Befunde selbst sind behoben (done.md). Offen:
- **Produktion prüfen (nach dem Deploy):** `.env` auf dem Server - `JWT_SECRET` mindestens 32 Zeichen (sonst wird er gestreckt, `JwtSecret`, und alle müssen sich einmal neu anmelden; dann einen langen Zufallswert setzen), `ALLOWED_ORIGINS` leer (steht dort vermutlich noch `*`; die App braucht kein CORS), `APP_DEBUG=false`, `TRANSLATE_TOOL_ENABLED=false`, `CSP_MODE` nicht gesetzt oder `enforce`. Danach mit `curl -I https://ytan.pesr.org/` prüfen, dass die Sicherheits-Header (inkl. `Content-Security-Policy`) da sind und `http://` auf `https://` umleitet; ein paar Tage das PHP-Error-Log nach `YTAN CSP violation` durchsehen.
- **Android-App unter der CSP auf dem Gerät prüfen:** laut Capacitor-Quelle (8.5) unbetroffen (Bridge per `addDocumentStartJavaScript`), aber nicht auf einem echten Gerät getestet - nach dem Deploy Aufzeichnung, Garmin-Senden und das Update-Banner einmal ausprobieren; bei Problemen `CSP_MODE=report` setzen.
- **Login-Altlast ganz entfernen:** prüfen, ob in der Produktions-DB noch Klartext-Passwörter stehen (`SELECT COUNT(*) FROM user WHERE password IS NOT NULL AND password NOT LIKE '$2y$%'`); wenn nein, den Klartext-Zweig in `AuthService::login()` löschen.

## Leuchtfeuer-Quellen: nächste Schritte

Aus der Quellenrecherche (2026-10-14, Plan "Weitere Quellen für den Leuchtfeuer-Import"); OpenStreetMap als Quelle und die Quellen-Tabelle sind umgesetzt (done.md), offen sind:
- **NGA-Bände:** über die MSI-Schnittstelle sind alle Bände 110-116 ladbar (done.md); offen: die JSON-Daten sind **älter als die monatliche PDF-Ausgabe** (Band 116: 297 Internationale Nummern nur im JSON, d. h. inzwischen entfallen; zuletzt berichtigt meist 2015, spätestens 2021) - entscheiden, welche Quelle für welchen Zweck gilt, ggf. beim Laden des JSON gegen die PDF-Ausgabe abgleichen (`nga` = PDF, `ngaapi` = Schnittstelle werden nicht zusammengeführt); die PDF-Bände 110-115 lassen sich weiter einzeln mit `bin/import-lighthouse-list.php nga <pdf>` einlesen (Download: `https://msi.nga.mil/api/publications/download?key=16694312/SFH00000/NIMA/LoL/NGA_LoL_Pub<NNN>.pdf`, NNN = 110-116, Liste aller Schlüssel: `GET https://msi.nga.mil/api/publications/stored-pubs`). Staging-Spalte `last_seen_at`, damit in einer neuen Ausgabe weggefallene Einträge erkennbar sind.
- **Dansk Fyrliste** ist Ausgabe 2022 (Korrekturen nur über Efterretninger for Søfarende/Niord): neuere Ausgabe bei der Søfartsstyrelsen erfragen.
- **Nationale offene Dienste prüfen** (GetCapabilities, enthalten die Layer Kennung/Sektoren/Reichweite/Nummer?): Finnland Traficom/Väylävirasto (CC BY 4.0), Norwegen Kystverket "Navigasjonsinstallasjon" (NLOD 2.0, WFS `maps.kystverket.cloudgis.no`); nur bei brauchbaren Attributen als Quellen `fi`/`no`.
- Optional: in der Importliste markieren, wenn dieselbe `international_id` auch aus einer anderen Quelle gestaged oder schon als POI vorhanden ist.

## Anwenderdokumentation auf Englisch

Die Anleitung (`docs/user/de/`, `/help`) gibt es bisher nur auf Deutsch; bei der Sprache Englisch zeigt `/help` die deutsche Fassung samt deutschen Seitentexten und einem Hinweis. Ein Verzeichnis `docs/user/en/` mit denselben Dateinamen (und Frontmatter `title`/`keywords`) reicht - `UserGuideService` nimmt es automatisch. Die Screenshots sind deutsch beschriftet; für Englisch müsste `tests/e2e/screenshots/help.spec.js` mit englischer Locale laufen (eigener Dateiname-Zusatz oder eigenes Verzeichnis je Sprache).


## Standards für alle Gestaltungselemente festlegen

An verschiedenen Stellen sind die Gestaltungselemente wie Buttons, Inputfelder, Schalter, Hinweistexte etc. unterschiedlich gestaltet. Das muss einheitlich gestaltet werden. Dazu soll im Admin-Bereich eine Beispielseite aufgebaut werden, auf der möglichst alle Elemente vertreten sind, um die Gestaltung vergleichen zu können und schließlich anzugleichen. Ein Vorbild ist das Bootstrap Cheatsheet. Es kann sein, dass einige der folgenden Elemente noch gar nicht zum Einsatz kommen.

Es sollen u. a. Bootstrap Floating Labels verwendet werden.

Gestaltungselemente sind u.a. (nicht vollzählig):
- allgemeine Typografie (Standardfonts)
- einfache Texte
- Warnungen
- Listen
- Paginierungssteuerung (Pagination)
- Popups
- infoWindows (Google Maps)
- Überschriften (h1, h2, ...) Inputfelder
- Textareas
- Inputfelder
- Passwortfelder
- Fehlermeldungen
- Pflichtfelder
- Zurück- und Schließenbuttons
- Dropdowns
- Badges
- Carousel
- Buttons mit Text
- Buttons mit Symbolen
- Buttons mit Symbolen und Text
- Pillswitches
- Tabellen (Inhalt, Spaltenüberschriften)
- deaktivierte Elemente
- Checkboxen
- Radiobuttons
- Schieberegler
- Datei-Upload
- Validation
- Akkordeons
- Modals
- Toasts
- Spinners
- Tooltips
- Progressbars

Es geht hier nur um die Gestaltung des öffentlichen Bereichs. Das Theming des Adminbereichs auf Basis von AdminLTE ist hier nicht gemeint.

**Stand (2026-09-18):** Phase 1 ist umgesetzt (Vergleichsseite `/admin/styleguide` mit sieben Kernkategorien, Bootstrap vendored; Buttons und InfoWindow-Eingabefelder vereinheitlicht - Verlauf in done.md, "Standards für alle Gestaltungselemente - Zwischenstände"). **Offen:** die restlichen ~23 Kategorien der Liste auf der Vergleichsseite, die sechs im öffentlichen Bereich fehlenden Komponenten (Pagination, Akkordeon, Progressbar, Spinner, Datei-Upload, Carousel - bekommen einen echten Erstentwurf) und die eigentliche Vereinheitlichung der übrigen nebeneinandergestellten Varianten.

## Themes

Auf der Beispielseite im Admin-Bereich soll auch die Farbgestaltung überprüft werden können. Es soll zwischen verschiedenen Themes (z. B. Dark und Light) umgeschaltet werden können.

Es soll die Erstellung weiterer Farbthemes ermöglicht werden, bei der ein Admin neue Farben definieren kann und das Ergebnis sofort auf der Beispielseite angezeigt bekommt. Bestehende Themes können angepasst werden.

Es geht hier nur um die Gestaltung des öffentlichen Bereichs. Das Theming des Adminbereichs auf Basis von AdminLTE ist hier nicht gemeint.

## Wetterdaten

### Gesonderte Datenquellen für bestimmte Länder/Regionen: Finnland/FMI
Umgesetzt sind SMHI (Schweden und internationale Ostseegewässer) sowie DWD, DMI, MET Norway, Météo-France und KNMI über Open-Meteos `models=`-Parameter (Verlauf in done.md, "Wetterdaten: Quellen ..."). **Offen:** Finnland/FMI - WFS/XML-Format, eigener API-Key, daher ein echter neuer Provider (`WeatherProviderInterface`) mit Eintrag in `WeatherRegionResolver`. Die Architektur erlaubt weitere Regionen.

### Interpolation von Niederschlagsmengen
Niederschlagsmengen, die für einen Zeitraum von z. B. 3 Stunden angegeben wurden, müssen auf die einzelnen Stunden verteilt werden (9 mm von 12 bis 15 Uhr → je 3 mm für 12, 13 und 14 Uhr). **Zurückgestellt (Recherche 2026-09-19):** Bei Open-Meteo (stündlich) und SMHI (`precipitation_amount_mean` ist bereits ein Mittelwert in mm/h) tritt das Problem nicht auf. Die Umverteilung wird erst gebaut, wenn FMI oder ein anderer Anbieter mit echten Mehrstunden-Summen angebunden wird, damit sie gegen echte Daten entworfen werden kann.

## Mobile-Anzeige-Audit (2026-09-10): am echten Gerät gegenprüfen

Die gefundenen Fehler sind behoben (done.md, "Mobile-Anzeige-Audit"). Unter Mobile-Emulation nicht abschließend testbar, daher am echten Gerät prüfen:
- Route- und Area-Bearbeiten-Dialoge: nutzen dieselbe (als korrekt bestätigte) Markup-Struktur wie der POI-Dialog, konnten aber wegen ungenauer Klick-Positionierung auf der Route-/Area-Linie im Test nicht vollständig geöffnet werden.
- "Add to tour"-Popup aus dem Routen-InfoWindow heraus: aus demselben Grund nicht getestet.
- Kontextmenüs (Long-Press) auf Route/Area-Linien: für POIs auf dem echten Gerät bestätigt, für Linien nicht separat verifiziert - der Hit-Test-Mechanismus ist aber derselbe.
