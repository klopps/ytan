# Offene Punkte

## Wasserindex: Seen ab 0,1 km²

Seen ab 0,1 km² statt ab 10 km² in den Wasserindex aufnehmen: In Deutschland gibt es viele direkt miteinander verbundene kleinere Seen, die bisher fehlten (Autorouting, Abstände). Testlauf Deutschland (2026-11-25): 4.423 statt 40 Seen, 345.000 statt 39.000 Seen-Segmente, ca. +5 MB; 598 der Seen von 0,1 bis 10 km² liegen höchstens 20 m neben einem anderen See.

Stand 2026-11-25: `LAKE_MIN_KM2` in `tools/water/build_index.py` auf 0.1 gesetzt, Doku/Texte angepasst, Neubau des gesamten Index gestartet (Sicherung des alten Index: `D:\Daten\ytan\storage\water\backup-202611232232`). Offen: Ergebnis prüfen (Größe, Stichproben auf `/admin/water`, Autorouting über eine Seenkette), mit `bin\publish-water.bat` verteilen, danach `php bin/poi-water-distance.php --all` und die Abstände der Shelter-/Campingplatz-Importe (`distances --all`) neu rechnen; prüfen, ob die Filter "am See" der Importe in Skandinavien neue Schwellen brauchen.


## Tourenplanung mit Etmal

Mehrtägige Touren planen: Eine Tour (bzw. eine lange Route) wird anhand eines Etmals (Tagesstrecke, z. B. in km oder Stunden bei Standardgeschwindigkeit) in Tagesetappen aufgeteilt. Dabei sollen POIs entlang der Strecke berücksichtigt werden - Anlegestellen, Nachtlager (Shelter, Zeltplätze, Campingplätze), Möglichkeiten zum Wasser bunkern usw. -, damit die Etappen an sinnvollen Punkten enden und unterwegs Pausen- und Versorgungsmöglichkeiten sichtbar sind.


## Leuchtfeuer-Quellen: nächste Schritte

Aus der Quellenrecherche (2026-10-14, Plan "Weitere Quellen für den Leuchtfeuer-Import"); OpenStreetMap als Quelle und die Quellen-Tabelle sind umgesetzt (done.md), `last_seen_at`, die Markierung gleicher Nummern, die Prüfung der nationalen Dienste und deren Anbindung (Finnland, Norwegen) ebenfalls (done.md, "Leuchtfeuer-Quellen: ..."). Offen sind:
- **NGA-Bände, Entscheidung:** die JSON-Daten der MSI-Schnittstelle (`ngaapi`) sind **älter als die monatliche PDF-Ausgabe** (`nga`; Band 116: 297 Internationale Nummern nur im JSON, d. h. inzwischen entfallen; zuletzt berichtigt meist 2015, spätestens 2021). Entscheiden, welche Quelle für welchen Zweck gilt (die Importliste zeigt jetzt pro Eintrag "Auch in: ..." und "Nicht gesehen seit"; ein Eintrag, der nur in `ngaapi` steht, ist der Kandidat für "inzwischen entfallen"). Die PDF-Bände 110-115 lassen sich weiter einzeln mit `bin/import-lighthouse-list.php nga <pdf>` einlesen (Download: `https://msi.nga.mil/api/publications/download?key=16694312/SFH00000/NIMA/LoL/NGA_LoL_Pub<NNN>.pdf`, NNN = 110-116, Liste aller Schlüssel: `GET https://msi.nga.mil/api/publications/stored-pubs`).
- **Finnland (`fi`), zwei Annahmen prüfen** (die Quelle ist gebaut, done.md "Leuchtfeuer-Quellen: Finnland und Norwegen"): (1) die Peilungen `alkukulma`/`loppukulma` der Sektoren sind als "von See aus gesehen" angenommen wie in den Listen - bei Norwegen ist das gemessen, bei Finnland nicht; an einem bekannten Sektorfeuer (Vergleich mit NGA/OSM oder der Seekarte) prüfen, sonst stehen die Sektoren dort um 180 Grad gedreht. (2) Es werden nur Seezeichen mit `toimintatilakoodi` = 1 geladen; 0, 2 und 4 kommen vor (zusammen 40 von 635), ihre Bedeutung ist nicht dokumentiert - klären, ob 2/4 z. B. nur saisonal abgeschaltete Feuer sind, die fehlen sollten.




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

