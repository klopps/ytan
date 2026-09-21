# Offene Punkte

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

**Zwischenstand (2026-09-18) - Phase 1 (Kernauswahl) umgesetzt, Punkt bleibt offen:** Neue Vergleichsseite unter `/admin/styleguide` (`templates/admin-styleguide.php`, `public/js/admin-styleguide.js`, Route in `src/App.php`) - bewusst NICHT über die AdminLTE-Shell (`admin-shell-header.php`/`-footer.php`), da jene explizit kein `style.css`/`fonts.css` lädt ("two competing CSS systems"); eigener `<html><head>` wie `templates/set-password.php`, admin-gated über das bestehende `initAdminAuth()`. Zeigt bisher sieben Kern-Kategorien (Typografie, Buttons, Inputfelder/Passwortfelder inkl. Bootstrap-Floating-Label, Badges, Checkboxen/Radiobuttons/Pillswitches, Toasts, Warnungen), jede mit Quellenangabe zur Nachverfolgung im Code. Bootstrap-CSS wurde dafür neu vendored (`public/lib/bootstrap/bootstrap.min.css`, v5.3.8, passend zur bereits vorhandenen `bootstrap.bundle.min.js`), nur auf dieser Seite geladen, vor `style.css` eingebunden. Eigener, schlanker Theme-Umschalter (Light/Dark) oben auf der Seite - bewusst nicht über `map-core.js`s `setTheme()`/`settings.js`s `saveSettings()` (lesen ~20 hartkodierte `detail0`..`detail16`-Checkbox-IDs, die es hier nicht gibt).

Beim Bauen zwei nicht triviale Bugs gefunden und behoben, die für künftige Standalone-Seiten relevant bleiben: (1) `style.css`s `html, body { overflow: hidden; }` ist nur für die SPA gedacht - ohne die `standalone-page`-Klasse auf `<html>` (wie bei allen anderen eigenständigen Seiten) ließ sich die Seite nach einem Bildschirm nicht weiterscrollen, der restliche Inhalt war schlicht abgeschnitten. (2) Bootstraps eigene `.toast`-Komponente (`display:none` bis eine `.show`-Klasse gesetzt wird) kollidierte mit dem gleichnamigen `.toast` aus `public/js/toast.js` (nutzt `.toast-visible` statt `.show`) - echte Toasts blieben unsichtbar, bis eine gezielte Override-Regel nach beiden Stylesheets ergänzt wurde. Zusätzlich zeigte sich, dass Bootstraps Formularelemente ihre eigenen `--bs-*`-Variablen nutzen und dem Dark-Theme sonst nicht folgen - gelöst, indem der Theme-Umschalter zusätzlich Bootstraps eigenes `data-bs-theme`-Attribut mitsetzt.

Bewusst noch nicht in diesem Schritt: die restlichen ~23 Kategorien aus der Liste oben sowie die sechs im öffentlichen Bereich aktuell komplett fehlenden Komponenten (Pagination, Akkordeon, Progressbar, Spinner, Datei-Upload, Carousel) - letztere bekommen laut Absprache einen echten Erstentwurf statt Platzhaltern, aber erst in einem Folgeschritt. Die eigentliche Vereinheitlichung (Angleichen der hier nur nebeneinandergestellten Varianten) ist ebenfalls ein separater, späterer Schritt. Verifiziert per Playwright, mobil (390×660) und Desktop (1440×900), Light und Dark; volle PHPUnit-Suite weiterhin grün (213 Tests).

**Zwischenstand (2026-09-18) - erste Vereinheitlichung umgesetzt (Buttons):** Anhand der neuen Vergleichsseite fiel die Größen-/Gestaltungs-Inkonsistenz bei Buttons besonders auf - Größe und Optik von `.button`/`.startbtn`/`.button-danger` (bislang: 10px bzw. 16px Schrift, `border-radius:5px`, `.button-danger` mit abweichender Outline-Optik) in `public/css/style.css` auf dieselben Werte wie die bereits einheitliche `.nav-btn-primary`/`.nav-btn-secondary`/`.nav-btn-secondary.nav-btn-danger`-Familie umgestellt (`padding:10px 14px; border-radius:10px; font-size:13.5px; font-weight:700`), rein per CSS-Wertänderung unter gleichbleibenden Klassennamen - keine Änderungen an `public/js`/`templates` nötig, da alle ~14 Aufrufstellen (Confirm-Dialog app-weit, jedes POI/Route/Area-Bearbeiten-Fenster, Cookie-Menü, Track-Recorder, Tour-Admin-Aktionszeile, drei Auth-Seiten) die Klassennamen unverändert weiterverwenden. Die drei Button-**Arten** (Bestätigen/primär, Abbrechen/neutral, Logout-destruktiv) bleiben wie gewünscht farblich unterschieden; `.button-danger` wechselt dabei von einem roten Outline-Rahmen (füllte beim Hover komplett rot) auf dieselbe sanfte Flächenfarbe wie der Logout-Button im Profil-Drawer. Bewusst unverändert gelassen: das Breitenverhalten (`.nav-btn-*` bleibt volle Blockbreite für den alleinstehenden Drawer, `.button`/`.startbtn` bleiben automatisch breit, da meist mehrere nebeneinanderstehen) sowie bestehende kontextuelle Overrides. Bewusst ausgeschlossen (keine "Bestätigen/Abbrechen/Logout"-Buttons): `.nav-chip-btn` (Filter-Chip, nicht Aktions-Button), alle Icon-only-Buttons (`.nav-back`, `.toolbar-icon-btn` etc. - eigenes, noch offenes Touch-Target-Thema), `.second-toolbar-end-btn`/`.map-search-mode-btn` (Toolbar-Widgets). Per Playwright verifiziert (mobil 390×660 und Desktop 1440×900, Light und Dark): alle sechs Buttons auf der Styleguide-Seite jetzt gleich groß; ein direkt nachgebauter Save/Remove/Cancel-Dreier wie im echten POI-Bearbeiten-Fenster passt bei ~300px Containerbreite noch in eine Zeile, bricht bei 280px (untere Grenze der realen Google-InfoWindow-Breite) auf zwei Zeilen um - kein Überlappen/Bruch, nur ein zusätzlicher Zeilenumbruch durch die größeren Buttons. Keine Backend-Änderung, `composer test` daher nicht erneut nötig.

**Nachtrag (2026-09-18):** Nutzer meldete direkt danach, dass die Buttons in den Google-Maps-InfoWindows (POI/Route/Area bearbeiten) jetzt deutlich zu hoch wirken - zurecht: der oben nachgebaute Dreier-Test hatte nur die Zeilenumbruch-Frage geprüft, nicht die Button-Höhe selbst. Gemessen: `.button` war durch die Vereinheitlichung von ca. 22px auf ca. 39px Höhe gewachsen (fast doppelt) - für die knappe Save/Remove/Cancel-Zeile in einem ~300px breiten InfoWindow spürbar zu viel. Neue, gezielte Override-Regel `div.infoWindowElement .button { padding: 5px 10px; font-size: 13px; }` direkt bei `div.infoWindowElement` in `style.css` ergänzt - wiederverwendet bewusst exakt dieselben kompakten Werte, die `.cm-panel-header .startbtn` für denselben "zu voller Button für zu wenig Platz"-Grund schon einsetzt, statt eine dritte Button-Größe zu erfinden. Radius/Fettung/Farben (also die App-weite Zugehörigkeit zur selben Button-Familie) bleiben erhalten, nur Innenabstand/Schriftgröße schrumpfen. Ergebnis: ca. 28px Höhe statt 39px. Per Playwright verifiziert - mit dem nachgebauten Dreier-Test (Light und Dark) und zusätzlich am echten, per Klick geöffneten POI-Anlegen-InfoWindow im Browser (nicht nur synthetisch nachgebaut).

**Nachtrag 2 (2026-09-18):** Nutzer meldete anschließend dieselbe Beobachtung für die Eingabefelder (Text/Textarea/Select) in denselben InfoWindows - andere Eckenrundung und andere Größe als der Rest der App, aber ausdrücklich mit der Vorgabe, dass die Felder dabei allenfalls nur wenig größer werden dürfen. Ursache: `#editPoiName`/`#editPoiType`/`#editPoiDescription`/`#editPoiLighthouseSectorCharacteristic`/`#editPoiURL`/`#editPoiWSI`/`#editPoiLighthouseCharacteristic`/`#editRouteName`/`#editRouteDescription`/`#editAreaName`/`#editAreaZindex`/`#editAreaDescription` waren acht über die Datei verstreute, inhaltlich identische ID-Regeln aus der Zeit vor der `.nav-field`-Umstellung (`border-radius:2px`, die kräftige `--color-border`-Farbe statt `-subtle`, größtenteils **gar kein** Padding - reiner Browser-Default). `#editPoiType`s ID-Regel überschrieb dabei sogar noch zusätzlich das eigene `.select-css` (das selbst schon nur 3px Radius hat) auf 2px herunter - derselbe ID-schlägt-Klasse-Mechanismus wie beim `#userLoginPassword`-Fund in einer früheren Session. Alle acht Regeln zu einer gemeinsamen Regel in `style.css` zusammengeführt: `border-radius:10px` (voll an `.nav-field input` angeglichen - beim Radius gibt es keinen Grund, nicht anzugleichen), `border-color: var(--color-border-subtle)`, aber bewusst nur `padding: 4px 8px` statt `.nav-field input`s vollem `10px 12px` (Vorgabe "nur wenig größer"). Gemessen am echten Namensfeld: Höhe von 19,6px auf 25,6px (~+31%, nicht verdoppelt wie zuvor bei den Buttons). `box-sizing: border-box` ergänzt, da diese Felder sich selbst über `width: calc(100% - 6px)` bemessen und das zusätzliche Padding sie sonst über die schmale InfoWindow-Spalte hinaus verbreitert hätte. Per Playwright verifiziert (Light und Dark, am echten POI-Bearbeiten-InfoWindow im Browser sowie synthetisch für die Route/Area-Variante derselben Regel) - Ecken und Randfarbe jetzt einheitlich mit dem Rest der App, Feldgröße nur moderat gewachsen. Keine Backend-Änderung.

**Nachtrag 3 (2026-09-18):** Direkte Folge des vorigen Nachtrags - das zusätzliche Padding ließ die Feldbeschreibungen (z.B. "Name: *" in `.leftCol`) nicht mehr mittig zum Feldinhalt in `.rightCol` erscheinen. Ursache: `div.infoWindowElement .leftCol` hatte noch nie ein eigenes `padding-top`, das kam bisher nur zufällig dadurch grob hin, dass Browser-Default-Inputs kaum eigenes Padding hatten; das neue `padding-top:4px` der Felder schob deren Text sichtbar nach unten, während das Label an Ort und Stelle blieb. `padding-top: 3px` auf `div.infoWindowElement .leftCol` ergänzt, um das auszugleichen. Gemessen (Name-Feld): vertikale Mitte von Label und Eingabefeld weichen jetzt nur noch um 0,6px voneinander ab (vorher spürbar mehr). Bewusst bei einfachem Float-Layout geblieben statt auf Flexbox umzustellen, obwohl das robuster gegen künftige Padding-Änderungen wäre: dieselbe `.leftCol`/`.rightCol`-Regel wird unverändert auch von `set-password.php`/`forgot-password.php` mitgenutzt, die dort aber ohnehin per eigenem Override auf `float:none`/`width:100%` (gestapelte Darstellung) umschalten - eine Flexbox-Umstellung an der gemeinsamen Basisregel hätte dort unnötiges Risiko für nicht direkt sichtbare Nebenwirkungen bedeutet. Per Playwright verifiziert (Light und Dark, am echten POI-Bearbeiten-InfoWindow im Browser sowie mit einer pixelgenauen Mittenabstand-Messung).

## Themes

Auf der Beispielseite im Admin-Bereich soll auch die Farbgestaltung überprüft werden können. Es soll zwischen verschiedenen Themes (z. B. Dark und Light) umgeschaltet werden können.

Es soll die Erstellung weiterer Farbthemes ermöglicht werden, bei der ein Admin neue Farben definieren kann und das Ergebnis sofort auf der Beispielseite angezeigt bekommt. Bestehende Themes können angepasst werden.

Es geht hier nur um die Gestaltung des öffentlichen Bereichs. Das Theming des Adminbereichs auf Basis von AdminLTE ist hier nicht gemeint.

## Wetterdaten

### Gesonderte Datenquellen für bestimmte Länder/Regionen

Für die Wettervorhersage sollen je nach Abfrageort unterschiedliche Dienste genutzt werden. Für Wetterdaten für Orte in
- Deutschland: DWD
- Dänemark: DMI Open Data
- Schweden: SMHI Open Data
- Norwegen: MET Norway
- Finnland: FMI Open Daten
- Frankreich: Météo-France 
- Niederlande: Open-Meteo KNMI Weather Model API
- Internationale Ostseegewässer: SMHI
- für alle anderen open-meteo.com
Die Architektur soll es erlauben, später für weitere Regionen gesonderte Datenquellen zu nutzen.

Es ist also erforderlich, dass immer zunächst ermittelt werden muss, in welcher Region der Abfrageort liegt, um anschließend die richtige Quelle zu ermitteln und von dort die Daten abzufragen.

Die Quelle der jeweiligen Vorhersage (open-meteo, DWD, DMI etc.), das verwendete Wettermodell, der Zeitpunkt der Abfrage und - falls vorhanden - der Zeitpunkt der Generierung der Daten beim jeweiligen Wetterdienst müssen gespeichert werden.

**Zwischenstand (2026-09-18) - Architektur + SMHI umgesetzt, Punkt bleibt offen:** Mit dem Nutzer abgestimmt nacheinander vorzugehen: dieser erste Schritt deckt nur diesen Unterpunkt ab, die übrigen drei (Löschen veralteter Daten, Fehlende Daten, Interpolation von Niederschlagsmengen) sowie der separate "Wetterdatenhinweis"-Punkt unten folgen später.

Rechercheergebnis, das den Zuschnitt verändert hat: 5 der 7 genannten Dienste (Deutschland/DWD, Dänemark/DMI, Norwegen/MET Norway, Frankreich/Météo-France, Niederlande/KNMI) sind über die ohnehin schon genutzte Open-Meteo-API mit einem anderen `models=`-Parameter erreichbar - kein eigener API-Key, kein neuer HTTP-Client nötig (z.B. `models=icon_eu` für DWD, `models=dmi_harmonie_arome_europe` für DMI). Nur Finnland/FMI (WFS/XML-Format, eigener API-Key) und Schweden + internationale Ostseegewässer/SMHI (im März 2026 auf eine neue API migriert) brauchen eine echte, separate Anbindung. Auf Wunsch des Nutzers wurde **SMHI** als der eine echte neue Provider für diesen Schritt gebaut (deckt sowohl Schweden als auch die im Todo explizit genannten internationalen Ostseegewässer ab); DWD/DMI/MET-Norway/Météo-France/KNMI (günstig, nur Open-Meteo-Modellparameter) und FMI (aufwändiger) bleiben spätere Schritte.

Umgesetzt:
- Neue Tabelle `weather_forecast` (`database/migrations/022_create_weather_forecast.sql`) ersetzt den bisherigen reinen Datei-Cache (`storage/weather-cache/*.json`, gelöscht) - `UNIQUE KEY` auf (lat, lng) sorgt schon jetzt dafür, dass neue Daten für einen Ort die alten automatisch ersetzen (Upsert), ohne dass dafür schon der separate "Löschen"-Schritt nötig wäre. Speichert `source`/`model`/`generated_at` (letzteres nullable, "falls vorhanden") wie gefordert.
- `WeatherService` (`src/Service/WeatherService.php`) wurde vom reinen Open-Meteo-Client zum Orchestrator: `WeatherRegionResolver` (`src/Service/Weather/`) ermittelt per Koordinate die Region, `WeatherProviderInterface` (`OpenMeteoWeatherProvider`/`SmhiWeatherProvider`) liefert die General-Vorhersage (Temperatur/Wind/Niederschlag/Wettercode) je nach Region. Marine-/Gezeitendaten und Sonnenauf-/-untergang kommen bewusst weiterhin immer von Open-Meteo (kein SMHI-Äquivalent vorhanden, astronomisch unabhängig vom Wettermodell) - nur die eigentliche Wettervorhersage wird geroutet.
- Regions-Ermittlung: `GeocodingService` (schon vorhanden für die Orts-Titelzeile im Wetter-Panel) um `resolveCountryCode()` erweitert - nutzt denselben Nominatim-Aufruf/Cache-Eintrag wie die Orts-Namensauflösung (`addressdetails=1` ergänzt), keine zweite Anfrage gegen Nominatims strikt ratenlimitierten Dienst. Land Schweden → SMHI; kein Land ermittelbar (offenes Meer) UND innerhalb einer groben Ostsee-Bounding-Box (bewusst nur eine erste Näherung, kein exaktes Polygon) → SMHI; sonst → Open-Meteo.
- SMHIs neue Punkt-Vorhersage-API (`opendata-download-metfcst.smhi.se/.../snow1g/...`, kein API-Key) live erprobt und angebunden - zwei nicht offensichtliche Korrekturen waren nötig, damit die Werte korrekt erscheinen: Windgeschwindigkeiten kommen in m/s (nicht km/h wie beim Rest der App) und mussten umgerechnet werden; SMHIs eigene Wettersymbol-Skala (1-27) wurde auf die WMO-Codes abgebildet, die `weather.js`s Icon-Logik erwartet, sonst wären falsche Wettersymbole anzeigt worden.
- Response-Format additiv erweitert (`source`/`model`/`generated_at` auf oberster Ebene) - `WeatherController`/`weather.js` unverändert, die neuen Felder werden noch nicht in der sichtbaren "Wetterdaten von..."-Anzeige verwendet (das ist der spätere "Wetterdatenhinweis"-Schritt).

Getestet: 24 neue/angepasste PHPUnit-Tests (Provider-Auswahl, SMHI-Wertumrechnung, Regions-Ermittlung inkl. Bounding-Box-Grenzfälle, echtes DB-Upsert-Verhalten via neuem `tests/Integration/WeatherForecastRepositoryTest.php`), volle Suite grün (237 Tests). Zusätzlich live gegen die echten APIs verifiziert (nicht nur Fixtures): ein deutscher Küstenpunkt liefert weiterhin Open-Meteo-Daten, ein schwedischer und ein Punkt auf offener Ostsee liefern beide echte, korrekt umgerechnete SMHI-Daten inkl. weiterhin von Open-Meteo stammender Wellendaten, alle drei korrekt in der neuen `weather_forecast`-Tabelle abgelegt.

**Zwischenstand (2026-09-18) - Unterpunkt "Löschen veralteter Daten" umgesetzt:** Die eine Hälfte ("bei neuen Daten alte löschen") war bereits durch den `UNIQUE KEY`/Upsert aus dem vorigen Schritt erledigt. Neu: `WeatherForecastRepository::deleteOlderThan(int $days): int` sowie `bin/cleanup-weather-data.php` - das **erste wiederkehrende/Cron-Skript im gesamten Projekt** (bisher gab es nur manuell/deploy-zeit ausgeführte `bin/*.php`-Skripte), löscht alle `weather_forecast`-Zeilen älter als 7 Tage. Läuft nirgends automatisch - der Nutzer hat bestätigt, auf beiden produktiven Hosts selbst einen Cronjob einrichten zu können; empfohlene Zeile dafür jetzt in `CLAUDE.md`s Commands-Abschnitt dokumentiert (täglich nachts), damit sie nicht nur hier im Verlauf steht. 4 neue Integrationstests (löscht nur Zeilen jenseits des Cutoffs, lässt frische unangetastet, Grenzfall knapp diesseits/jenseits von 7 Tagen ohne Race gegen die Sekundenauflösung der Zeitstempel), volle Suite grün (240 Tests). Zusätzlich live gegen die echte Dev-DB verifiziert: eine künstlich 10 Tage alte und eine 1 Tag alte Testzeile eingefügt, Skript ausgeführt, genau die alte wurde entfernt, die frische (und eine echte, vom laufenden Betrieb stammende Zeile) blieben unangetastet.

**Zwischenstand (2026-09-19) - Unterpunkt "Fehlende Daten" umgesetzt:** Kein theoretisches Problem - live gegen SMHIs echte API geprüft: stündliche Auflösung gilt nur für ~2,5 Tage, danach 3h/6h/12h-Lücken bis Tag 10, die `WeatherService`/`SmhiWeatherProvider` bisher unverändert durchgereicht hätten (irreführend kompakte Zeitleiste, plus ein `hour.precipitation.toFixed(1)`-Aufruf in `weather.js`, der bei einem echten `null`-Wert sofort abgestürzt wäre). Neue Methode `WeatherService::fillHourlyGaps()`: baut unabhängig ein 168-Stunden-Raster (nicht abhängig von einer erfolgreichen Open-Meteo-Antwort), füllt pro fehlender Stunde zuerst per linearer Interpolation zwischen den eigenen Nachbarwerten des Primär-Anbieters (≤4h Abstand; Windrichtung zirkulär über den kürzeren Bogen, nicht naiv über die 0°/360°-Grenze; Wettercode kategorisch per nächstgelegenem Nachbarn statt Mittelwert), sonst mit Open-Meteos eigenem Wert für exakt diese Stunde (deckt sowohl größere Lücken als auch fehlende Resttage einheitlich ab) - "keine Daten" (alle Felder `null`) bleibt der letzte Ausweg, nur falls sogar die Open-Meteo-Anfrage fehlschlägt. `weather.js` bekam die davor komplett fehlende `null`-Behandlung: `isWeatherHourEmpty()`-Prüfung in der Stunden-Spalte (gedämpfte "keine Daten"-Zelle statt Absturz), sowie in Tages-Übersicht und Wochendiagramm (vorher hätte `Math.max/min.apply(null, ...)` eine `null`-Temperatur stillschweigend als `0` gewertet und Tages-Min/Max verfälscht; das Wochendiagramm zeichnet eine einzige durchgehende, nach Index positionierte Kurve, daher dort `nearestKnownValues()` zum Überbrücken statt Herausfiltern). Getestet: 3 neue Fälle in `WeatherServiceTest` (kurze Lücke interpoliert inkl. Windrichtungs-Sonderfall, weite Lücke via Open-Meteo gefüllt, doppelter Ausfall → `null`), volle Suite grün (243 Tests). Live gegen die echte SMHI-API verifiziert: 168 Stunden, 0 `null`-Werte, Tag-3-Werte mit klar erkennbaren interpolierten Nachkommastellen (z. B. 13.733333...). Im Browser (mobil 390×660 und Desktop 1440×900, Light und Dark) zusätzlich mit künstlich eingefügten `null`-Stunden geprüft (da die echten Daten aktuell lückenlos sind) - gedämpfte "keine Daten"-Spalten rendern sauber, kein Absturz, Wochendiagramm bleibt durchgehend glatt.

**Zwischenstand (2026-09-19) - die 5 "günstigen" Länder (Deutschland/DWD, Dänemark/DMI, Norwegen/MET Norway, Frankreich/Météo-France, Niederlande/KNMI) ergänzt:** Wie in der Recherche vom SMHI-Schritt festgestellt, brauchen diese fünf keinen eigenen HTTP-Client - Open-Meteos eigene Vorhersage-API akzeptiert einen `models=`-Parameter, der intern auf das jeweilige nationale Modell umschaltet, liefert aber weiterhin im selben Antwortformat. `WeatherRegionResolver` bekam eine neue `COUNTRY_MODELS`-Zuordnungstabelle (Ländercode → `[model, sourceLabel]`, z. B. `de` → `icon_seamless`/`DWD`, `dk` → `dmi_seamless`/`DMI`, `no` → `metno_seamless`/`MET Norway`, `fr` → `meteofrance_seamless`/`Météo-France`, `nl` → `knmi_seamless`/`KNMI`) - bei Treffer bleibt der Provider weiterhin `PROVIDER_OPEN_METEO`, aber `WeatherRegion` trägt jetzt zusätzlich `openMeteoModel`/`sourceLabel`, die `OpenMeteoWeatherProvider::fetchGeneralForecast()` als `models=`-Query-Parameter durchreicht bzw. `WeatherService::fetchAndCombine()` statt des generischen "Open-Meteo"-Labels als `source` in die Antwort schreibt. Alle fünf `..._seamless`-Modellvarianten blenden bei Bedarf automatisch mit ECMWF nach, liefern also weiterhin die vollen 168 Stunden ohne Lücken - `fillHourlyGaps()` (siehe oben) greift für keines der fünf Länder ein, live gegen alle fünf echten Provider-Antworten bestätigt (0 Aufrufe an den Fallback-Pfad). Ein unbekannter/nicht gelisteter Ländercode fällt weiterhin auf Open-Meteos eigenes Standardmodell zurück (`openMeteoModel`/`sourceLabel` bleiben `null`, Anzeige bleibt schlicht "Open-Meteo"). Getestet: neue `WeatherRegionResolverTest`-Fälle (je Land eigener Datenprovider-Testfall plus ein "nicht gelisteter Ländercode" Gegentest), `WeatherServiceTest` um einen Ländermodell-Fall sowie einen "kein Gap-Filling nötig"-Fall ergänzt, volle Suite grün (255 Tests). Live-Verifikation gegen alle fünf echten APIs (curl, nicht nur Fixtures): Deutschland → DWD/icon_seamless, Dänemark → DMI/dmi_seamless, Norwegen (Bergen) → MET Norway/metno_seamless, Frankreich (Brest) → Météo-France/meteofrance_seamless, Niederlande (Den Helder) → KNMI/knmi_seamless, jeweils 168 Stunden ohne Lücken; Gegenproben (Polen als nicht gelistetes Land → weiterhin generisches "Open-Meteo", Schweden → weiterhin unverändert SMHI) bestanden ebenfalls. Im Browser geprüft (mobil 390×660, Desktop 1440×900, Light/Dark) am Beispiel Frankreich/Brest: Hinweiszeile zeigt korrekt "Wetterdaten: Météo-France, meteofrance_seamless von ...", Zeitleiste und Wochendiagramm durchgehend ohne Lücken. Einzige noch offene Besonderheit aus diesem Land-Zuschnitt: Finnland/FMI (siehe Rechercheergebnis oben) bleibt der einzige noch fehlende, echte Zusatz-Provider - der Unterpunkt "Gesonderte Datenquellen" bleibt deshalb als Ganzes noch offen.

### Löschen veralteter Daten
- Wenn für einen Ort neue Wetterdaten vorliegen, sollen ältere Daten aus der Datenbank gelöscht werden.
- Alle Wetterdaten, die älter als 7 Tage sind, sollen regelmäßig (mindesten einmal täglich) aus der Datenbank entfernt werden.

### Fehlende Daten
Liefert eine API nicht die Daten für alle künftigen 7 Tage, so sollen die Daten durch die von open-meteo.com ergänzt werden. Liefert ein Vorhersagemodell keine stündlichen Werte, so sollen die fehlenden Daten interpoliert werden, jedoch maximal für einen Zeitraum von 4 Stunden. Andernfalls muss das System anzeigen, dass keine Daten vorliegen.

### Interpolation von Niederschlagsmengen
Dabei ist zu beachten, dass Niederschlagsmengen, die für einen Zeitraum von z. B. 3 Stunden angegeben wurden und nicht auf stündlicher Basis auf 3 einzelne Stunden verteilt werden müssen. Werden bspw. für einen Zeitraum von 3 Stunden zwischen 12 und 15 Uhr 9 mm Niederschlag vorhergesagt, dann müssen für die Stunden 12, 13 und 14 jeweil 3mm Niederschlag angenommen werden.

**Rechercheergebnis (2026-09-19), Umsetzung zurückgestellt:** Geprüft, ob dieses Problem bei den aktuell angebundenen Quellen überhaupt auftritt. Open-Meteo liefert `precipitation` ohnehin durchgängig stundengenau (kein Bucket-Verhalten). SMHIs `precipitation_amount_mean` - das einzige Feld, bei dem für die spärlicher werdenden Tage 3-7 mehrstündige Intervalle (6h/12h) statt stündlicher Werte vorkommen - ist laut SMHIs eigener Parameter-Dokumentation bereits ein **Mittelwert in mm/h** für das jeweilige Intervall, keine Gesamtsumme fürs Intervall - das im Todo beschriebene "9mm über 3h → durch 3 teilen"-Problem tritt dort also nicht auf, der aktuelle Code (reicht `precipitation_amount_mean` unverändert je Stunde durch) ist für SMHI bereits korrekt. Einzig das noch nicht gebaute FMI (Finnland, siehe oben "Gesonderte Datenquellen") könnte laut erster Recherche tatsächlich Mehrstunden-Summen statt Mittelwerten liefern. Mit dem Nutzer abgestimmt: die eigentliche Umverteilungs-Logik wird erst gebaut, wenn FMI (oder ein anderer Anbieter mit echten Mehrstunden-Summen) tatsächlich integriert wird, damit sie gegen echte Daten entworfen und getestet werden kann statt spekulativ ins Leere.


### Wetterdatenhinweis
Der Hinweis "Wetterdaten: <Dienst>, von <Datum> / <Uhrzeit>" in der Wetterdatenazeige soll direkt unter der Überschrift mit dem Ort und den Koordinaten oberhalb der horizontalen Linie stehen.

**Zwischenstand (2026-09-19) - umgesetzt:** Zwei Teile. (1) Text erweitert: `weatherFormatFetchedAt()` (`public/js/weather.js`) bekommt jetzt `source`/`model` aus der API-Antwort übergeben (die seit dem Architektur-Schritt schon vorhanden waren, aber noch nirgends angezeigt wurden) und baut je nachdem, ob ein Modell vorhanden ist, einen von zwei neuen i18n-Strings zusammen - `"Wetterdaten: {source} von {date} / {time}"` (Open-Meteo hat kein eigenes Modell, "Open-Meteo" allein benennt die Quelle schon eindeutig) oder `"Wetterdaten: {source}, {model} von {date} / {time}"` (z. B. SMHI/snow1g) - statt eines hängenden Kommas bei fehlendem Modell. (2) Position korrigiert: der Hinweis (`.weather-timeline-meta`) saß bisher NACH `.weather-timeline-header`, also unterhalb von dessen `border-bottom` (der im Todo gemeinten "horizontalen Linie" - es gibt kein eigenes `<hr>`-Element). In `templates/app.php` in den Header verschoben (Titel+Schließen-Button in eine neue `.weather-timeline-header-row` gekapselt, der Hinweis direkt darunter, beide noch innerhalb der umrandeten Header-Box) - `style.css`s `.weather-timeline-header` entsprechend von einer einzeiligen Flex-Zeile auf eine zweizeilige Flex-Spalte umgestellt. Im Browser geprüft (mobil 390×660 und Desktop 1440×900, Light und Dark): beide Text-Varianten (mit und ohne Modell) rendern korrekt direkt unter dem Titel, die horizontale Trennlinie liegt jetzt wie gefordert darunter statt darüber. Keine Backend-Änderung, `composer test` daher nicht erneut nötig.




## Mobile-Anzeige-Audit (2026-09-10)

Alle Dialoge/Bildschirme unter echter Mobile-Emulation (412×915) durchgetestet.

**Bereits behoben:**
- Forgot-Password-Seite (`/forgot-password`): Karte ragte über den Bildschirmrand (fehlendes `box-sizing: border-box` bei `#forgotPasswordBox` - das Padding wurde zur 95%-Breite addiert statt darin enthalten zu sein). Behoben.
- Set-Password-Seite (`/set-password`): identischer Fehler bei `#setPasswordBox`. Behoben.

**Behoben (2026-09-11):**
- GDPR-Einwilligungsbildschirm: Der Lupe-Button (Kartensuche, oben links) überlappte den Einwilligungstext, obwohl der Nutzer noch gar nicht zugestimmt hatte. Ursache: `.map-search-wrapper` hatte kein `display: none` als Ausgangszustand (anders als `#sidemenu-toggle`, das schon immer erst nach der Zustimmung eingeblendet wird). Jetzt standardmäßig ausgeblendet und wird wie der Hamburger-Button erst in `loadGoogleMaps()` nach Zustimmung sichtbar.
- Derselbe Fehler auch beim GPS-Standort-Button (`#gotomylocation`, unten rechts) gefunden und auf dieselbe Art behoben.
- "privacy policy"-Link im GDPR-Dialog verwies auf `/legal/datenschutz` (keine registrierte Route, 404) statt auf `/legal/privacy` wie überall sonst in der App. Korrigiert.
- Direkt aufgerufene Datenschutz-/Impressum-/About-/Passwort-Seiten (z.B. über den jetzt korrigierten GDPR-Link, der `target="_blank"` nutzt) ließen sich nicht scrollen und hatten keinerlei Rand (Inhalt direkt an der Bildschirmkante): Sie laden dieselbe style.css wie die SPA, deren `html, body { overflow: hidden; margin: 0; padding: 0; }` nur für die SPA gedacht ist (dort hat jeder scrollbare/gepolsterte Bereich seinen eigenen inneren Container). Alle sechs eigenständigen Seiten (about/confirm-email/forgot-password/imprint/privacy/set-password) tragen jetzt eine `standalone-page`-Klasse auf `<html>`, die normales Scrollen und 20px Rand zurückgibt, ohne den SPA-Fix anzutasten.
- Überschriften auf diesen Seiten (z.B. Datenschutzerklärung) wirkten bei Zeilenumbruch zu eng: `line-height: 1.2em` auf `html, body` berechnete sich als fester Pixelwert relativ zur Basis-Schriftgröße (16px) und wurde von h1/h2 unverändert geerbt, statt sich an deren eigene (viel größere) Schriftgröße anzupassen - eine 32px-Überschrift hatte dadurch nur 19.2px Zeilenhöhe, weniger als die Schrift selbst hoch ist. Auf `line-height: 1.2` (ohne Einheit) geändert, wodurch sich der Wert korrekt pro Element neu berechnet. Betrifft die ganze App, nicht nur diese Seiten, aber überall nur reparierend (Elemente in Basis-Schriftgröße bleiben unverändert).

**Geprüft, keine Probleme gefunden:**
- About-/Impressum-/Datenschutz-Seiten
- Drawer: Root, POIs, Site Settings, Preferences (inkl. Profil bearbeiten / Passwort ändern)
- Tours-Panel (Liste, Detail, Formular, Edit Routes) und Users-Panel (Liste, Formular) - in dieser Sitzung bereits mehrfach gefixt und erneut bestätigt
- POI-Bearbeiten-Dialog (Google-InfoWindow)
- Bestätigungsdialog (Löschen), Captcha-Dialog, Toast-Benachrichtigungen
- "Second Toolbar" (Editing-Anzeige), Tour-Mode-Badge

**Nicht abschließend testbar - am echten Gerät gegenprüfen:**
- Route- und Area-Bearbeiten-Dialoge: nutzen dieselbe (als korrekt bestätigte) Markup-Struktur wie der POI-Dialog, konnten aber wegen ungenauer Klick-Positionierung auf der Route-/Area-Linie im Test nicht vollständig geöffnet werden.
- "Add to tour"-Popup aus dem Routen-InfoWindow heraus: aus demselben Grund nicht getestet.
- Contextmenüs (Long-Press auf Route/Area/POI): für POIs auf dem echten Gerät bestätigt (siehe oben). Für Route/Area-Linien wegen ungenauer Klick-Positionierung im Test nicht separat verifiziert - der Hit-Test-Mechanismus ist aber derselbe.

