# Offene Punkte

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
