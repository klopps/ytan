---
title: Karteninhalte
keywords: Route, POI, Gebiet, Klick, Doppelklick, Rechtsklick, Long-Press, Standort, POI-Typen, Entfernungen, Abstand zum Wasser, Gewässerabstand, Sektorlicht, Quelle, Routenanimation
---

Was die Symbole, Linien und Flächen auf der Karte bedeuten und was passiert, wenn du sie antippst.

> **Tippen, Doppeltippen, lang Drücken:** Auf dem Smartphone tippst du, am Rechner klickst du. Der **Rechtsklick** am Rechner entspricht dem **langen Druck** auf dem Smartphone.

## Routen

Eine Route ist eine farbige Linie aus Wegpunkten. Sie hat einen Namen, eine Länge, eine Beschreibung und optional Fotos.

| Aktion | Was passiert |
|---|---|
| **Einfach tippen / klicken** | Die Route zeigt an ihren Wegpunkten die Entfernungen: grün die Strecke vom **Start**, rot die Strecke bis zum **Ende**. Noch einmal tippen blendet sie wieder aus. |
| **Doppeltippen / Doppelklick** | Das Infofenster öffnet sich mit Name, Länge und Beschreibung. |
| **Lang drücken / Rechtsklick** | Das Kontextmenü mit allen Aktionen öffnet sich. |

![Die Entfernungen entlang einer Route](route-entfernungen.jpg)

Im Infofenster stehen unten links **Zur Tour hinzufügen** und - wenn du eine Garmin-Uhr eingerichtet hast - das **Uhr-Symbol** zum Senden an die Uhr (hervorgehoben, wenn die Route schon auf der Uhr liegt), rechts der **Stift** zum Bearbeiten. Bei einer aufgezeichneten Route nennt das Fenster außerdem, wer sie wann aufgezeichnet hat und wie lange es dauerte (wenn du dieses Recht hast).

![Infofenster einer Route](route-info.jpg)

### Das Kontextmenü einer Route

![Kontextmenü einer Route](route-kontextmenue.jpg)

| Eintrag | Wirkung |
|---|---|
| **Route bearbeiten** | Wegpunkte ändern, siehe [Anlegen und Bearbeiten](#chapter-anlegen-bearbeiten). |
| **Info bearbeiten** | Name, Beschreibung, Farbe, Fotos, Sichtbarkeit. |
| **Zur Tour hinzufügen** | Hängt die Route an eine bestehende oder neue Tour. |
| **Teilen** | Erzeugt einen Link auf diese Route (nur bei öffentlichen Routen). |
| **Zeige Routenanimation** | Spielt die Route als Kameraflug ab, siehe [Routen- und Tourenanimation](#chapter-animation). |
| **An Garmin-Uhr senden** | Schickt die Route auf deine Uhr, siehe [Garmin-Uhr](#chapter-garmin-uhr). Geht mit jeder Route, die du siehst, auch mit fremden. Nur sichtbar, wenn die Uhr eingerichtet ist. |
| **Route exportieren (GPX 1.1)** | Lädt die Route als GPX-Datei herunter. |
| **Route löschen** | Entfernt die Route. Gehört sie zu einer Tour, musst du das mit einer kleinen Rechenaufgabe bestätigen, und die Ersteller der Touren werden benachrichtigt. |
| **Abbrechen** | Schließt das Menü. |

Nicht angemeldet siehst du nur **Zeige Routenanimation** und **Route exportieren** (falls der Export für alle freigegeben ist). **Bearbeiten**, **Teilen** und **Löschen** erscheinen nur bei eigenen Routen (Administratoren dürfen alle).

## POIs - Orte

Ein POI ist ein einzelner Ort, den ein Symbol markiert. Die Farbe und das Bild zeigen den **Typ**:

| Typ | Verwendung |
|---|---|
| Allgemeine Ortsmarkierung | alles, was in keine andere Kategorie passt |
| Zeltplatz | wilde und inoffizielle Zeltplätze (mit Windschatten-Indikator) |
| Anlegestelle | Stellen zum An- und Ablegen |
| Trinkwasser | Zapfstellen, Brunnen |
| Toilette | |
| Historischer Ort | |
| Gefahrenstelle | Steine, Strömung, Schiffsverkehr, Sperrungen |
| Naturschönheit | |
| Einkaufen | |
| Umtragestelle | Stellen zum Umtragen des Bootes |
| Unterstand | Hütten, Schutzhütten |
| Campingplatz (kommerziell) | mit Windschatten-Indikator |
| Medizinische Versorgung | |
| Verein / Einrichtung | z. B. Kanuvereine |
| Leuchtfeuer | Leuchttürme und Seezeichen, mit Kennung und Sektoren |
| Parkplatz | |
| Angeln | |

| Aktion | Was passiert |
|---|---|
| **Tippen / Klicken** | Das Infofenster mit Name, Typ, Beschreibung, Link und Fotos. |
| **Doppeltippen / Doppelklick** | Die Karte zoomt auf den Ort. |
| **Lang drücken / Rechtsklick** | Das Kontextmenü. |

![Infofenster eines POIs](poi-info.jpg)

Je nach POI zeigt das Infofenster außerdem:

- **Abstand zum Wasser** (Wellen-Symbol): wie weit es zum nächsten **Meer**, **Fluss** und **See** (ab 10 km²) ist, zum Beispiel „Meer 39 m · Fluss 10,9 km". YTAN berechnet das beim Anlegen und Verschieben selbst, für Orte in Europa und für die meisten POI-Typen. Werte über 100 km fehlen.
- **Quelle** bei Leuchtfeuern: Stammt es aus einer amtlichen Leuchtfeuerliste oder aus OpenStreetMap, nennt das Fenster die Quelle samt Lizenz. Bei anderen übernommenen POIs (Vereine, Shelter und Campingplätze, etwa aus OpenStreetMap) steht die Quelle in der Beschreibung.
- **Zeige Sektorlicht** bei Leuchtfeuern mit Sektoren: zeichnet die farbigen Lichtsektoren auf die Karte (noch einmal tippen blendet sie aus).
- **POI verschieben** (Kreuzpfeil-Symbol, nur bei eigenen POIs), siehe [Anlegen und Bearbeiten](#chapter-anlegen-bearbeiten).

Das Kontextmenü eines POIs enthält **Wetterdaten für diesen Ort**, bei Leuchtfeuern **Sektorleuchtfeuer anzeigen/ausblenden** und bei eigenen POIs **Bearbeiten**, **POI verschieben** und **Löschen**.

![Kontextmenü eines POIs](poi-kontextmenue.jpg)

Bei **kleinem Zoom** (weit herausgezoomt) fasst YTAN dicht beieinanderliegende POIs zu einer Zahl zusammen; ein Tipp darauf zoomt hinein.

## Gebiete

Ein Gebiet ist eine eingefärbte Fläche, zum Beispiel ein Vogelschutzgebiet oder ein Gebiet mit Befahrensregeln. Der Name und die Beschreibung stehen im Infofenster, das sich per **Tippen** öffnet; **Lang drücken / Rechtsklick** öffnet das Kontextmenü (Wetterdaten, Bearbeiten, Löschen).

![Infofenster eines Gebiets](gebiet-info.jpg)

## Der Standort-Button

Der runde Button unten rechts (über dem Street-View-Männchen) holt deine aktuelle Position:

1. Tippe ihn an. Beim ersten Mal fragt der Browser, ob YTAN deinen Standort verwenden darf - bestätige das.
2. Die Karte springt zu dir, und ein **pulsierender roter Punkt** markiert die Stelle.
3. Tippe den Punkt an: Das Fenster zeigt deine **Koordinaten** und bietet **Wetter für Standort** sowie **Koordinaten kopieren**.

Die Koordinaten erscheinen im Format, das du unter [Einstellungen](#chapter-einstellungen) gewählt hast.

> **Hinweis:** Der Standort wird nur auf deinen Wunsch und nur in deinem Gerät verwendet; YTAN speichert ihn nicht.
