# Offene Punkte

## Garmin: Formatierung ETA-Zeile
Die Restzeit bis zur Ankunft in Klammern hinter der ETA soll immer im Format hh:mm mitfolgendem "h" dargstellt werden. Beispiel: "ETA 14:25 (1:21 h)". Die Zeile soll zentriert werden.

## Garmin-Datenfeld auf der Uhr überprüfen

Das Connect-IQ-Datenfeld (`watch/`) ist gebaut, läuft im Simulator und hat auf der echten Uhr (fenix7pro) bereits Route und Anzeige geliefert (Details und Verlauf in done.md, "Routeninformationen auf Garmin Smartwatch"). Noch **nicht auf einer echten Uhr geprüft**:
- Hinweise: Vibration + Ton je Wegpunkt, drei lange Vibrationen + Erfolgston + "Ziel erreicht"-Einblendung am Ziel.
- Abkürzungserkennung (`skipAhead()`) und Startwahl bei Rundtouren auf echter Fahrt.
- ETA (Glättung, Pausen) samt Standard-Durchschnittsgeschwindigkeit aus dem Profil.
- Kompassring: Blickrichtung im Stand (Kompass, Kalibrierung) und in Fahrt, Lesbarkeit der Dreiecke.
- Textfarben aus Profil > Garmin-Uhr (auch auf fenix6 mit wenig Farben) und Verhalten bei hellem Hintergrund.
- Untere Zeile "gefahren | Reststrecke" (`|→ 821 m   12.6 km →|`, Pfeile als Pixel-Bitmaps `watch/assets/*.png` (18x10 und 14x8), in Textfarbe punktweise gezeichnet; `info.elapsedDistance` der Aktivität): zählt sie ab Aktivitätsbeginn und bleibt in Pausen stehen? Passt sie bei langen Werten (bis ca. 100 km) und auf der fenix6?
- Anzeige ohne GPS-Fix bzw. am Ziel: zeigt jetzt zusätzlich den Routennamen (im Simulator geprüft).
- fenix6-Build insgesamt (nur kompiliert, nie auf der Uhr gewesen).
- Voraussetzung: Migrationen 027/028 und die neue API sind auf Production deployt, die Uhr mit `bin\build-watch.bat - GERÄT` neu gebaut.

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
