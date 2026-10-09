---
title: Anlegen und Bearbeiten
keywords: Werkzeugleiste, Route anlegen, Wegpunkt, Route teilen, Split, POI anlegen, Windschatten-Indikator, WSI, Leuchtfeuer, Gebiet anlegen, Löschen, Fotos, öffentlich, privat
---

Mit der Werkzeugleiste legst du Routen, POIs und Gebiete an. Dazu musst du angemeldet sein. Alles Neue ist zunächst **privat**.

![Die Werkzeugleiste](werkzeugleiste.jpg)

## Routen

### Eine neue Route zeichnen

1. Tippe in der Werkzeugleiste auf **Route anlegen**. Das Symbol wird hervorgehoben.
2. Tippe auf die Karte: Jeder Tipp setzt einen **Wegpunkt** am Ende der Linie. Die bisherige Gesamtlänge steht oben in der Leiste.
3. Tippe auf **END EDITING**, wenn die Route fertig ist.
4. Im Fenster **Route** gibst du die Daten ein (siehe unten) und tippst auf **Speichern**.

### Wegpunkte ändern

Beim Zeichnen und beim Bearbeiten einer bestehenden Route (Kontextmenü > **Route bearbeiten**) gilt:

| Aktion | So geht es |
|---|---|
| **Hinzufügen** | Auf die Karte tippen - der neue Punkt wird ans **Ende** der Route angehängt. |
| **Verschieben** | Einen Wegpunkt mit dem Finger oder der Maus an die neue Stelle ziehen. |
| **Entfernen** | Den Wegpunkt antippen. |

![Eine Route im Bearbeitungsmodus](route-bearbeiten.jpg)

> **Hinweis:** Punkte lassen sich nicht in die Mitte einer Route einfügen. Willst du die Linie in der Mitte ändern, entferne die Punkte hinter der Änderung und zeichne sie neu - oder teile die Route (siehe unten) und zeichne den Teil neu.

### Name, Farbe und mehr

![Das Route-Fenster](route-dialog.jpg)

| Feld | Bedeutung |
|---|---|
| **Name** | Mindestens drei Zeichen. |
| **Beschreibung** | Freier Text, zum Beispiel Hinweise zu Strömung oder Anlegestellen. |
| **Farbe** | Farbe der Linie auf der Karte. |
| **Fotos** | Bis zu mehrere Fotos; zu große werden automatisch verkleinert. Sie werden beim Speichern übertragen. |
| **Route umkehren** | Dreht Start und Ende um. |
| **Öffentlich (für alle sichtbar)** | Ohne Haken sieht nur du die Route. |

Mit **Entfernen** löschst du die Route, mit **Abbrechen** verwirfst du die Änderungen.

### Eine Route teilen (aufspalten)

Eine Route lässt sich an einem Wegpunkt in **zwei Routen** zerlegen, zum Beispiel um aus einer langen Strecke Tagesetappen zu machen:

1. Öffne die Route zum Bearbeiten (Kontextmenü > **Route bearbeiten**).
2. Drücke auf einen **inneren** Wegpunkt lange (am Rechner: Rechtsklick).
3. Bestätige die Frage „Route an diesem Wegpunkt in zwei Routen teilen?" mit **Ja, Route hier teilen**.

Die erste Route behält den Anfang bis zu diesem Punkt und heißt „… (Teil 1)", die zweite bekommt den Rest als „… (Teil 2)". Beschreibung, Farbe, Fotos und Sichtbarkeit werden kopiert; in Touren steht die neue Route direkt hinter der ursprünglichen.

Die Route muss dazu bereits gespeichert sein.

## POIs

### Einen POI anlegen

Es gibt zwei Wege:

- Werkzeugleiste > **POI anlegen**, dann auf die Karte tippen, **oder**
- auf die gewünschte Stelle lange drücken (Rechtsklick) und **POI erstellen** wählen.

![Das POI-Fenster](poi-dialog.jpg)

| Feld | Bedeutung |
|---|---|
| **Name** | Mindestens drei Zeichen. |
| **Typ** | Siehe [Karteninhalte](#chapter-karteninhalte). Je nach Typ erscheinen weitere Felder. |
| **Beschreibung** | Freier Text. |
| **URL** | Eine Internetadresse, z. B. die Seite des Campingplatzes. |
| **Fotos** | Wie bei Routen. |
| **Öffentlich (für alle sichtbar)** | Ohne Haken sieht nur du den POI. |

### Sonderfall: Windschatten-Indikator (WSI)

Bei **Zeltplatz** und **Campingplatz (kommerziell)** gibst du an, aus welchen Richtungen der Platz vor Wind **geschützt** ist. Dazu teilt YTAN den Kreis in **16 Sektoren zu je 22,5°**, beginnend im Norden und im Uhrzeigersinn. Jeder Sektor hat einen von drei Werten:

| Wert | Bedeutung |
|---|---|
| **0** | ungeschützt |
| **1** | teilweise geschützt |
| **2** | geschützt |

Der WSI ist eine Zeichenfolge aus genau 16 Ziffern. Am einfachsten füllst du sie mit dem **WSI Editor**: Tippe auf einen Sektor, um zwischen den drei Werten zu wechseln, und bestätige mit **Übernehmen**. Auf der Karte zeigt ein kleiner Kreis um das Symbol das Ergebnis (Schalter **Windschatten-Indikatoren** im Menü > POIs; er ist standardmäßig aus).

![Der WSI Editor](poi-wsi-editor.jpg)

### Sonderfall: Leuchtfeuer

Bei **Leuchtfeuer** (Leuchttürme, Seezeichen) trägst du die **Kennung** ein, zum Beispiel „Iso WRG 6s". YTAN kann daraus den Verlauf der Lichtsektoren auf der Karte zeichnen: Im Kontextmenü des Leuchtfeuers schaltest du **Sektorleuchtfeuer anzeigen** ein oder aus.

## Gebiete

1. Werkzeugleiste > **Gebiet anlegen**.
2. Tippe nacheinander die **Ecken** der Fläche auf die Karte. Wegpunkte lassen sich wie bei Routen verschieben und entfernen.
3. Tippe auf **END EDITING**.
4. Im Fenster **Gebiet** legst du fest:

| Feld | Bedeutung |
|---|---|
| **Name** und **Beschreibung** | Was das Gebiet ist und welche Regeln gelten. |
| **Farbe** | Füllfarbe. |
| **Deckkraft** | Wie durchscheinend die Fläche ist (0,05 bis 1,0). |
| **Z-Index** | Reihenfolge, wenn sich Gebiete überlappen: Die höhere Zahl liegt oben. |
| **Fotos**, **Öffentlich** | Wie bei Routen. |

![Das Gebiet-Fenster](gebiet-dialog.jpg)

Bestehende Gebiete änderst du über Kontextmenü > **Bearbeiten**.

## Ändern und Löschen

- **Ändern:** Kontextmenü des Elements > **Bearbeiten** (bei Routen **Route bearbeiten** für die Linie und **Info bearbeiten** für die Daten) oder der Stift im Infofenster.
- **Löschen:** Kontextmenü > **Löschen** (bei POIs und Gebieten) bzw. **Route löschen**. Eine Rückfrage verhindert versehentliches Löschen.

Eigene Inhalte kannst du immer ändern. **Fremde** Inhalte ändern nur Administratoren.

## Öffentlich oder privat?

| Einstellung | Wer sieht es? |
|---|---|
| **Privat** (Haken aus) | Nur du. |
| **Öffentlich** | Alle Besucher, auch ohne Konto. |

Im Menü > **POIs** kannst du Routen, Gebiete und POI-Typen ein- und ausblenden, siehe [Suche und Anzeige](#chapter-suche-und-anzeige).

## Ohne Netz arbeiten

Du kannst auch ohne Netz anlegen und ändern. Die Änderungen werden auf dem Gerät gemerkt und später übertragen, automatisch oder von Hand unter **Menü > Ausstehende Änderungen**. Siehe [Android-App](#chapter-android-app).
