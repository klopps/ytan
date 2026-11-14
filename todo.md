# Offene Punkte

## Routencheck: Tidegewässer und Strömung aus Messdaten

Folgepunkt zur Flussströmung im Routencheck (done.md, 2026-11-14). Offen:
- Tidebeeinflusste Flussabschnitte (Elbe unterhalb Hamburg, Weser, Ems, Eider ...): dort kehrt sich die Strömung mit der Tide um, die Fließrichtung aus OSM stimmt nur zeitweise. Bisher bekommen sie die normale Flussströmung. Idee: Flussabschnitte nahe einer Mündung ins Meer (z. B. < 30 km Flusslänge) als "tidebeeinflusst" markieren und keine Richtung rechnen, nur einen Hinweis; später Tidezeiten (Open-Meteo `tide_height`) für die Richtung nutzen.
- Strömung aus Messdaten statt pauschal: Pegelonline (WSV) liefert für Bundeswasserstraßen Wasserstand und teils Abfluss, aber keine Fließgeschwindigkeit (Querschnitt fehlt) - höchstens als "Hochwasser/Niedrigwasser"-Hinweis nutzbar.
- Bäche (`waterway=stream`) sind nicht im Wasserindex.

## Routencheck: KI-Einschätzung einer Route für einen Tag

Erste KI-Funktion (entschieden 2026-11-13). Auf eine Route tippen → "Routencheck" (EN "Route check", später für Touren "Tourcheck"): PHP bewertet die Route für Tag/Startzeit/Richtung/Tempo/Erfahrungsstufe mit einer Ampel (grün/gelb/rot, grau = keine Daten) je Abschnitt und je möglicher Startstunde, Claude (Anthropic API, `claude-sonnet-5-5`) formuliert daraus eine Einschätzung mit Zeitfenstern, Abschnitten, Ausweich-Anlandestellen und Hinweisen. Grundsatz: Zahlen rechnet PHP, die KI erklärt nur. Ergebnis als Bottom-Sheet, Karte bleibt sichtbar und färbt die Route abschnittsweise.

Entschieden:
- Zugang über neues Benutzerrecht `ai_use`; ein Admin-Schalter schaltet die komplette Funktion (Ampel + Text) systemweit an/aus; Tageslimit je Benutzer.
- Ampel-Kriterien: Mittelwind, Böen, Welle (Open-Meteo, gedeckelt durch Fetch-Schätzung), Gewitter, Dunkelheit, Wassertemperatur; Modifikatoren ablandiger Wind, Seitenwind + Welle, Lee/Exponiert über die freie Wasserstrecke gegen den Wind (Fetch aus dem lokalen Wasserindex, Inseln eingeschlossen). Schwellen auf /admin/ai einstellbar; Benutzer wählen nur ihre Erfahrungsstufe (Einsteiger/Erfahren/Experte = Faktor auf die Schwellen).
- API-Key nur in `.env` (`ANTHROPIC_API_KEY`, je Umgebung ein Workspace mit Spend Limit in der Anthropic Console; lokaler Key am 2026-11-13 erfolgreich getestet).
- KI-Text wird zwischengespeichert (Schlüssel = gesendeter Kontext, gerundete Wetterwerte, Gültigkeit einstellbar, Default 3 h); Treffer zählen nicht ins Limit.
- Admin-Seite /admin/ai: Schalter, Tageslimit, Verbindungstest, Verbrauch (Ø Tokens je Abfrage; Tabelle je berechtigtem Benutzer und Monat, sortierbar nach Benutzer/Abfragen/Tokens), Ampel-Regeln.
- Zurückgestellt: Restguthaben-Anzeige (Anthropic hat keine Balance-API; Idee: Aufladungen von Hand eintragen, Kosten aus protokollierten Tokens schätzen).

Plan mit allen Details: routencheck.md.

Stand 2026-11-13: gebaut und lokal getestet (PHPUnit 907 grün, davon neu `RouteRatingTest`, `BriefingRulesTest`, `RouteCheckTest`, Fetch-Tests in `WaterDistanceServiceTest`; e2e context-menu/route grün; Browser mobil 390×650 und Desktop). Erste echte Abfrage (Avernakø - Drejø, 18,6 km, Sonnet 5.5): ~2.700 Tokens Eingabe + ~1.100 Ausgabe, ~13 s, ≈ 1,7 Cent. Backend: Migration 070, `src/Service/Ai/*`, `AiUsageRepository`, `AiBriefingController`, `AiAdminController`, `WaterDistanceService::openWaterAlong()`, `PoiRepository::findInBox()`, Composer `anthropic-ai/sdk` + Guzzle (Plattform auf PHP 8.3 gepinnt). Frontend: `route-briefing.js`, Button im Routen-InfoWindow + Kontextmenü, `/admin/ai` (`admin-ai.js`), Recht im Benutzerformular.

Offen:
- Datenschutzerklärung: 2026-11-13 komplett auf den Stand von YTAN gebracht (`templates/privacy.php`, § 10 Routencheck; Hinweistext `route_check.consent` angeglichen - er behauptete "Wegpunkte" und "keine persönlichen Daten", gesendet werden aber keine Koordinaten, dafür die Namen eigener privater POIs). Vom Betreiber noch zu prüfen/freizugeben: Auftragsverarbeitung Hoster + Mail, Rechtsgrundlage der Übermittlung an Anthropic (DPF-Zertifizierung oder Standardvertragsklauseln, Vertragspartner), Löschfrist für `storage/logs` (derzeit keine) und Fotos mit EXIF-Daten (werden unverändert gespeichert).
- Deploy: Migration 070 läuft über `bin/migrate.php`; auf Test und Prod je einen eigenen Anthropic-Workspace-Key in die `.env` (`ANTHROPIC_API_KEY`), dann auf /admin/ai einschalten und Benutzern das Recht „Routencheck (KI)“ geben.
- Erfahrungsstufe zusätzlich im Profil einstellbar machen (bisher nur über die Chips im Routencheck, gespeichert per `PUT /auth/paddle-level`).
- e2e-Spec für den Routencheck (nur Ampel mit `text: false`, damit keine echten KI-Kosten entstehen).
- Später: Tourcheck (ganze Tour), Restguthaben-Anzeige (s. o.).



## Regenradar mit Kurzfristvorhersage als Kartenebene

Eigenes Regenradar über Google Maps für DE, DK und SE: Beobachtung der letzten 60 Minuten plus Vorhersage bis +120 Minuten, nutzbar bei Zoomstufe 12-16 (Grund für den neuen Anlauf: RainViewer liefert kostenlos nur bis Zoomstufe 7, siehe done.md "Regenradar-Layer").

Aufbau: Python-Job (Cron) holt Radar-Composites (bevorzugt OPERA, sonst DWD/SMHI/DMI), projiziert nach Web Mercator, rechnet den Nowcast (OpenCV-Optical-Flow) und schreibt PNG-Kacheln Zoom 5-8 plus manifest.json nach storage/radar. Neuer RadarController liefert Manifest und Kacheln unter /api/v1/radar/ (nicht außerhalb von /api/, sonst cached sw.php die PNGs dauerhaft) und skaliert ab Zoomstufe 9 die Zoom-8-Kachel per GD hoch. Neues public/js/radar.js (Vorlage: entfernte Version aus der Git-Historie) mit Zeitleiste, Prognose-Kennzeichnung, Legende und Quellenangabe.

Phase 0 (2026-10-17): Quelle ist OPERA über EUMETNET Open Radar Data (CC BY 4.0, ohne Schlüssel, anonym 200 Anfragen/h), `DBZH` alle 5 Minuten mit ~7 Minuten Verzögerung, 1-km-Raster in LAEA (die Doku sagt noch 2 km); nationale Quellen entfallen. DMI hat keine "nicht verändern"-Klausel (CC BY 4.0). Der Job läuft auf dem Webhost; per SSH geprüft: Python 3.13.5 und Cron vorhanden, kein pip/Compiler (venv mit `--without-pip` + `get-pip.py` geht, alle Pakete als Wheels außer pysteps - der Nowcast wird daher mit OpenCV-Optical-Flow + SciPy gebaut), GD fest eingebaut, kein Inode-Limit.

Phase 1-4 (2026-10-17) gebaut und lokal getestet: `tools/radar/` (Python-Job, 13 Unit-Tests), `RadarController`/`RadarTileService` (PHPUnit), `public/js/radar.js` (Drawer-Schalter, Kontextmenü, Zeitleiste mit Prognose-Teil, Legende, Offline-Sperre, "veraltet"-Hinweis), Anwenderdoku, About-Seite, docs/API.md. Kacheln bis Zoomstufe 8 statt 9, darüber vergrößert PHP. Abweichungen und Messwerte in wetterradar.md, "Stand der Umsetzung".

Offen:
- **Deploy der Verbesserungen vom 2026-11-06** (siehe done.md "Regenradar: Messung nach drei Wochen Betrieb"): danach im `radar.log` prüfen, dass "reprojection lookup loaded" statt "built" erscheint und die Meldung "the previous tick is still working" von ~60 pro Tag auf wenige fällt. Neu: `storage/radar/reproject-*.npz` (~0,5-0,8 GB, Quota 59 GB, 19 GB belegt).
- In der Android-App testen.
- Die Prognose rechnet auf Prod derzeit bis +60 min (25 Frames); 90/120 min sind nicht geprüft. Vor einer längeren Prognose `verify.csv` auch für diese Vorlaufzeiten schreiben lassen.
- Möglich: Overzoom ab Zoomstufe 13 wirkt blockig (1-km-Pixel), ggf. weicher vergrößern (`RadarTileService` nimmt `imagecopyresized` = nächster Nachbar; `imagecopyresampled` braucht Truecolor und danach wieder die Palette, sonst verschwimmen die Farbstufen).

Ein detaillierter Plan steht in wetterradar.md.


## Windkarte mit animierten Partikeln

Windebene in der Karte: animierte Partikel (je stärker der Wind, desto schneller) plus farbige Fläche der Windstärke mit geringer Deckkraft, Zeitleiste über die Vorhersagestunden. Gebiet = die Regionen des Regenradars (`storage/radar/config.json`), keine eigene Auswahl.

Quellen (geprüft 2026-10-18): DWD ICON-D2 (0,02°, alle 3 h, +48 h, einfache GRIB2-Packung) und ICON-EU (0,0625°, alle 3 h, bis +120 h, CCSDS-komprimiert). eccodes hat kein Windows-Wheel, pygrib keine Wheels; ein eigener kleiner Decoder mit numpy + `imagecodecs` dekodiert beide (Prototyp: ~80 ms pro Feld). Python-Job `tools/wind/` schreibt Datenblöcke (u/v als uint8, gzip) in drei Stufen nach `storage/wind/`, `WindController` liefert sie unter `/api/v1/wind/`, `public/js/wind.js` zeichnet Farbfläche und Partikel per Canvas 2D (Bildschirmraster bei `idle`, DPR 1, 30 fps, ein `stroke()` pro Frame, Pause bei Bewegung/Hintergrund/nach 60 s).

Entschieden (2026-10-18): Mittelwind als Standard (Böen nur per Admin-Einstellung), nördlich 70,5° N vorerst leer, feinere Modelle (MEPS, AROME) später als Phase 6, Wind und Radar schließen sich gegenseitig aus.

Phase 1-5 (2026-10-18) gebaut und lokal getestet, Phase 6 (feinere Modelle) offen: `tools/wind/` (Python-Job mit eigenem GRIB2-Leser, 28 Unit-Tests; echter Lauf für 8 Länder: 24 s, 211 MB Download, 29 MB Ergebnis), `WindController`/`WindDataService`, Admin-Seite /admin/wind (Migration 047/048, u. a. Deckkraft der Farbfläche), `public/js/wind.js` (Partikel + Farbfläche, Böen-Chip, Ausschluss mit dem Regenradar), e2e-Spec `wind.spec.js`, Anleitung (10-wetter.md), About-Seite, docs/API.md, CLAUDE.md. Messwerte und Abweichungen: windkarte.md.

Offen:
- Deploy, dann auf beiden Servern `imagecodecs` ins venv (`python -m pip install --only-binary=:all: imagecodecs`) und die Cron-Zeile von `radar_job.py` auf `tools/ytan_jobs.py` umstellen (ein Eintrag `*/5` für Radar und Wind nacheinander, Zeile in `tools/radar/README.md`); Migration 047 läuft über `bin/migrate.php`. Stand: prod hat `imagecodecs` (2026-10-19).
- In der Android-App testen (Akku, Frame-Zeit bei 2.000 Partikeln, Geste Ziehen/Zoomen).
- Messung am echten Handy: Zeit pro Frame und für den Raster-Neuaufbau (Ziel < 3 ms / < 30 ms; im Desktop-Chromium: 0,1-0,3 ms pro Frame ohne Rasterisierung).
- Laufzeit, Speicher und Verkehr nach dem Deploy des Fixes vom 2026-11-06 erneut prüfen (done.md "Regenradar: Messung nach drei Wochen Betrieb"): vorher 35-37 Veröffentlichungen pro Tag zu je ~600 MB Download / ~470 MB geschrieben (~21 GB Verkehr/Tag statt der geschätzten ~1,7 GB), `storage/wind` 1,2 GB (zwei Läufe à 565 MB). Erwartet danach: eine Veröffentlichung je neuem Modelllauf (EU, D2, global - grob 15-20 pro Tag); wenn das noch zu viel ist, auf vollständige Sätze warten (EU+D2 desselben Termins zusammen) oder `run_interval_hours` 6.
- Phase 6: MET Norway MEPS (2,5 km) und AROME als weitere L2-Quellen, siehe windkarte.md.
  - Prüfung am 2026-10-19, noch nichts gebaut: `thredds.met.no`/`api.met.no` lassen sich von dieser Entwicklungsmaschine aus nicht erreichen (DNS ok, TCP 443 läuft in den Timeout; `opendata.dwd.de` geht), MEPS ist deshalb nicht prüfbar - erst vom Prod-Server aus testen. AROME: der offene S3-Bucket `object.files.data.gouv.fr/meteofrance-pnt` braucht keinen Schlüssel (Range-Abrufe möglich), enthält aber nur Läufe bis 2026-05-11 (danach nichts mehr) und liefert pro Paket (SP1-SP3) nur 6-Stunden-Dateien von ~60 MB mit allen Feldern; der aktuelle Zugang läuft vermutlich über `portail-api.meteofrance.fr` (Schlüssel). Vor dem Bau klären: aktueller AROME-Zugang, GRIB2-Packung (Decoder kann nur 5.0/5.42), Download pro Lauf (grob 0,5-1 GB).
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
