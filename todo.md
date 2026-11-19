# Offene Punkte

## Windkarte mit animierten Partikeln

Windebene in der Karte: animierte Partikel (je stärker der Wind, desto schneller) plus farbige Fläche der Windstärke mit geringer Deckkraft, Zeitleiste über die Vorhersagestunden. Gebiet = die Regionen des Regenradars (`storage/radar/config.json`), keine eigene Auswahl.

Quellen (geprüft 2026-10-18): DWD ICON-D2 (0,02°, alle 3 h, +48 h, einfache GRIB2-Packung) und ICON-EU (0,0625°, alle 3 h, bis +120 h, CCSDS-komprimiert). eccodes hat kein Windows-Wheel, pygrib keine Wheels; ein eigener kleiner Decoder mit numpy + `imagecodecs` dekodiert beide (Prototyp: ~80 ms pro Feld). Python-Job `tools/wind/` schreibt Datenblöcke (u/v als uint8, gzip) in drei Stufen nach `storage/wind/`, `WindController` liefert sie unter `/api/v1/wind/`, `public/js/wind.js` zeichnet Farbfläche und Partikel per Canvas 2D (Bildschirmraster bei `idle`, DPR 1, 30 fps, ein `stroke()` pro Frame, Pause bei Bewegung/Hintergrund/nach 60 s).

Entschieden (2026-10-18): Mittelwind als Standard (Böen nur per Admin-Einstellung), nördlich 70,5° N vorerst leer, feinere Modelle (MEPS, AROME) später als Phase 6, Wind und Radar schließen sich gegenseitig aus.

Phase 1-5 (2026-10-18) gebaut und lokal getestet, Phase 6 (feinere Modelle) offen: `tools/wind/` (Python-Job mit eigenem GRIB2-Leser, 28 Unit-Tests; echter Lauf für 8 Länder: 24 s, 211 MB Download, 29 MB Ergebnis), `WindController`/`WindDataService`, Admin-Seite /admin/wind (Migration 047/048, u. a. Deckkraft der Farbfläche), `public/js/wind.js` (Partikel + Farbfläche, Böen-Chip, Ausschluss mit dem Regenradar), e2e-Spec `wind.spec.js`, Anleitung (10-wetter.md), About-Seite, docs/API.md, CLAUDE.md. Messwerte und Abweichungen: windkarte.md.

Offen (Android-Test, Handy-Messung, Verkehr und die optionalen Verbesserungen stehen seit 2026-11-19 in future.md):
- Phase 6: MET Norway MEPS (2,5 km) und AROME als weitere L2-Quellen, siehe windkarte.md.
  - Prüfung am 2026-10-19, noch nichts gebaut: `thredds.met.no`/`api.met.no` lassen sich von dieser Entwicklungsmaschine aus nicht erreichen (DNS ok, TCP 443 läuft in den Timeout; `opendata.dwd.de` geht), MEPS ist deshalb nicht prüfbar - erst vom Prod-Server aus testen. Erneut geprüft 2026-11-19: Es lag an diesem Rechner (toter IPv6-DNS-Server, done.md „Wetterabrufe: Fehlschläge merken und Ursache loggen“) - per IPv4 antworten `thredds.met.no` (301) und `api.met.no` (200) sofort. Der Katalog `thredds/catalog/mepslatest/catalog.xml` listet die Läufe als NcML (`meps_det_2_5km_YYYYMMDDTHHZ.ncml`, alle 3 h, dazu `meps_lagged_6_h_subset_*`, `*_vc_*`), aber OPeNDAP (`thredds/dodsC/mepslatest/meps_det_2_5km_….ncml.dds`) antwortete für jeden Lauf mit HTTP 500 „Storage … "datasetTracker_2" is not active“ und `thredds/catalog/meps25eps/` mit der Statusseite status.met.no - eine Störung bei MET Norway selbst. Zweiter Versuch 2026-11-19 06:33 UTC: unverändert - OPeNDAP (NcML und die direkten `.nc` unter `meps25eps/YYYY/MM/DD/`) HTTP 500 „datasetTracker_2“, NCSS HTTP 503, `fileServer` HTTP 500; nur die Kataloge antworten (auch `metpparchive/` mit MET Nordic 1 km, `met_analysis_1_0km_nordic_*` stündlich - Analyse, keine Vorhersage; die Vorhersage `met_forecast_1_0km_nordic_*` wäre eine Alternative zu MEPS mit 1 km). Später erneut die DDS abrufen und prüfen: Variablen `x_wind_10m`/`y_wind_10m`/`wind_speed_of_gust`, Lambert-Gitter (x/y, Umrechnung nötig), Zeitachse, Größe einer Abfrage. MEPS kommt als NetCDF über OPeNDAP/NCSS, nicht als GRIB2 - der eigene GRIB2-Leser hilft dort nicht. AROME: der offene S3-Bucket `object.files.data.gouv.fr/meteofrance-pnt` braucht keinen Schlüssel (Range-Abrufe möglich), enthält aber nur Läufe bis 2026-05-11 (danach nichts mehr) und liefert pro Paket (SP1-SP3) nur 6-Stunden-Dateien von ~60 MB mit allen Feldern; der aktuelle Zugang läuft vermutlich über `portail-api.meteofrance.fr` (Schlüssel). Vor dem Bau klären: aktueller AROME-Zugang, GRIB2-Packung (Decoder kann nur 5.0/5.42), Download pro Lauf (grob 0,5-1 GB).

## Sicherheit: offene Reste aus dem Audit vom 2026-10-16

Die Befunde selbst sind behoben (done.md). Offen:
- **Android-App unter der CSP auf dem Gerät prüfen:** laut Capacitor-Quelle (8.5) unbetroffen (Bridge per `addDocumentStartJavaScript`), aber nicht auf einem echten Gerät getestet - nach dem Deploy Aufzeichnung, Garmin-Senden und das Update-Banner einmal ausprobieren; bei Problemen `CSP_MODE=report` setzen.

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
