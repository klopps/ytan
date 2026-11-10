---
title: GPX-Import
keywords: GPX, Import, Export, Garmin Connect, Komoot, Wikiloc, Track, Wegpunkte, Datei
---

Mit dem GPX-Import übernimmst du Strecken, die du schon irgendwo anders hast, als YTAN-Routen.

## Was ist GPX?

**GPX** (GPS Exchange Format) ist das gängige Dateiformat für GPS-Daten. Eine GPX-Datei kann enthalten:

| Inhalt | Bedeutung |
|---|---|
| **Track** (`trk`) | Eine aufgezeichnete Strecke, meist mit vielen Punkten und Zeitstempeln - zum Beispiel eine gepaddelte Fahrt. |
| **Route** (`rte`) | Eine geplante Strecke mit wenigen Punkten. |
| **Wegpunkte** (`wpt`) | Einzelne markierte Orte mit Name und Beschreibung. |

YTAN versteht GPX 1.1 und 1.0 und behandelt Tracks und Routen gleich.

## Wo bekomme ich GPX-Dateien her?

- **Garmin Connect:** Aktivität öffnen > Zahnrad > **Exportieren als GPX**.
- **Komoot:** Tour öffnen > **Exportieren** > GPX.
- **Wikiloc, Outdooractive, Strava** und viele andere Plattformen bieten einen GPX-Download an.
- **GPS-Geräte und Uhren:** Die Dateien liegen im Speicher des Geräts (Ordner `GPX` oder `Activities`) und lassen sich per USB kopieren.
- **YTAN selbst:** Routen und Touren exportierst du als GPX-Datei, um sie in ein anderes Gerät zu laden.

## Der Import Schritt für Schritt

1. Öffne das Menü und tippe auf **GPX importieren**.
2. Tippe auf **GPX-Datei wählen** und suche die Datei auf deinem Gerät aus. Sie darf bis zu **5 MB** groß sein.
3. YTAN zeigt dir eine **Vorschau** mit allen Tracks und Routen der Datei, jeweils mit Länge, Datum und Zahl der Wegpunkte.

   ![Die Vorschau eines Imports](gpx-vorschau.jpg)

4. Wähle aus, was du übernehmen willst: einzelne Tracks oder **Alle auswählen**.
5. Entscheide, wie importiert wird (nur bei mehreren Tracks):
   - **Einzelne Routen** - jeder Track wird eine eigene Route, jede in einer eigenen Farbe.
   - **Zu einer Route** - alle gewählten Tracks werden aneinandergehängt.
6. Optional: **Die Routen zu einer Tour zusammenfassen** - legt gleich eine Tour an (dafür brauchst du das Recht „Touren anlegen").
7. Optional: **Mit Wegpunkten als POIs importieren** - jeder Wegpunkt mit Name oder Beschreibung wird ein allgemeiner POI. **Wegpunkte anzeigen** listet sie vorab auf. Hat ein Wegpunkt keinen Namen, heißt der POI „IMPORT: " plus die ersten 20 Zeichen der Beschreibung.
8. Tippe auf **1 Route importieren** (bzw. die Anzahl) - die Schaltfläche nennt, was passiert.

Danach zeigt dir YTAN das Ergebnis. **Route anzeigen** zoomt auf die neue Route, bei einer Tour öffnet **Tourdetails** deren Seite, **Weitere Datei importieren** startet von vorn.

> **Wichtig:** Alles, was du importierst, ist zunächst **privat**. Veröffentlichen kannst du es danach wie gewohnt über Bearbeiten.

## Was YTAN beim Import mit den Daten macht

- **Ausdünnen:** Ein GPS-Track hat oft Tausende Punkte. YTAN vereinfacht ihn auf höchstens **500 Punkte**, ohne dass sich der Verlauf sichtbar ändert. Die **Länge** wird aus den vollständigen Daten berechnet und bleibt genau.
- **Name und Beschreibung:** Stammen aus dem Track. Fehlen sie und die Datei enthält nur einen Track, nimmt YTAN die Angaben der Datei.
- **Aufzeichnungsdaten:** Hat der Track Zeitstempel, werden Aufzeichnungszeit und Dauer wie bei einer GPS-Aufzeichnung gespeichert.
- **Sicherheit:** Dateien mit eingebetteten Definitionen (DOCTYPE) werden abgelehnt.

Eine Datei ohne Tracks, Routen und Wegpunkte meldet YTAN als leer.

## Der Export

Umgekehrt: Im Kontextmenü einer Route steht **Route exportieren (GPX 1.1)**, in den Tour-Details **GPX exportieren**. Je Route entsteht ein Track; eine Tour wird eine Datei mit einem Track pro Route in der Reihenfolge der Tour. Ob du exportieren darfst, regeln Administratoren pro Benutzer und für die ganze Seite.
