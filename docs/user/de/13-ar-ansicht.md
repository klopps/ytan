---
title: AR-Ansicht
keywords: AR, Augmented Reality, Kamera, Kompass, Umkreis, POIs im Kamerabild, AR-Navigation, Route im Kamerabild, Sichtweite, Wegpunkt, Navigationsleiste, Kalibrieren, Leuchtfeuer, Kennung, Sektorenfeuer, Reichweite
---

Die AR-Ansicht legt die POIs um dich herum über das Bild der Kamera. Hältst du das Smartphone in eine Richtung, siehst du, welcher Zeltplatz, Anleger oder Leuchtturm dort liegt und wie weit er entfernt ist. Mit **AR-Navigation** zeigt sie dazu eine Route als Linie auf dem Wasser und führt dich von Wegpunkt zu Wegpunkt.

## Voraussetzungen

- Ein **Smartphone oder Tablet mit Kamera, Kompass und GPS**. Am Rechner gibt es die AR-Ansicht nicht; die Einträge erscheinen dort gar nicht.
- YTAN muss über eine sichere Verbindung (**https**) laufen. Beim ersten Start fragt das Gerät, ob YTAN **Kamera**, **Standort** und (auf dem iPhone) **Bewegung und Ausrichtung** nutzen darf. Erlaube alle drei.
- In der **Android-App** braucht die Kamera eine aktuelle App-Version. Meldet die Ansicht „Die Kamera ist in dieser App-Version nicht verfügbar", installiere das Update, siehe [Android-App](#chapter-android-app).

Die AR-Ansicht braucht kein Konto. Sie zeigt alle POIs, die du auch auf der Karte siehst.

## Die AR-Ansicht öffnen

- Menü > **AR-Ansicht**, oder
- im **Kontextmenü der Karte** (langer Druck auf einen Kartenpunkt) **AR-Ansicht (Kamera)**.

Das **×** oben rechts schließt die Ansicht wieder.

## Was du siehst

![Die AR-Ansicht: Anleger und Zeltplatz am Ufer einer Insel, an ihrer Spitze ein Leuchtfeuer, das von hier aus rot leuchtet](ar-ansicht.jpg)

- **POIs** erscheinen als runde Plaketten in der Farbe ihres Typs mit Name und Entfernung. Ein Stiel zeigt auf die Stelle am Horizont, wo der POI liegt.
- Es werden nur POIs der Typen gezeigt, die du unter Menü > **POIs** eingeschaltet hast, und nur innerhalb des **Umkreises** (siehe unten).
- Damit das Bild übersichtlich bleibt, zeigt YTAN höchstens so viele POIs, wie unter **POIs im Bild höchstens** eingestellt ist - die nächsten im Blickfeld. Ein näherer POI liegt vor einem weiter entfernten. Liegen zwei in derselben Richtung, rückt der fernere nach oben.
- **Pfeile am linken und rechten Rand** zeigen, wie viele weitere POIs im Umkreis links oder rechts außerhalb des Bildes liegen.
- Oben steht die **GPS-Genauigkeit** und wie viele POIs im Umkreis liegen.

Tippst du auf eine Plakette, öffnet sich unten eine **Infokarte** mit Name, Typ, Entfernung und Richtung. **Auf Karte zeigen** schließt die AR-Ansicht und öffnet das Infofenster des POIs auf der Karte.

## Leuchtfeuer

Ein Leuchtfeuer mit eingetragener Kennung erscheint nicht als Plakette, sondern als **kleiner Turm mit Laterne**. Die Laterne leuchtet in der Farbe des Feuers und blinkt in seinem Rhythmus, zum Beispiel dreimal kurz bei „Fl(3) G 10s“. Zwischen den Blitzen bleibt das Glas schwach in der Lichtfarbe sichtbar. Der Rhythmus läuft nicht im Takt mit dem echten Feuer.

Unter dem Namen steht immer die vollständige Kennung, davor ein Punkt in der Farbe, die du von deiner Position aus siehst.

- **Sektorenfeuer:** Laterne und Farbpunkt zeigen die Farbe des Sektors, in dem du gerade stehst. Wechselst du den Sektor, wechselt auch die Farbe.
- **Kein Sektor:** Liegt deine Position in keinem Sektor, ist das Feuer von dort nicht zu sehen. Es erscheint **grau** mit dem Hinweis „von hier nicht sichtbar“.
- **Reichweite:** Ein Feuer mit Reichweite erscheint nur, wenn du innerhalb dieser Reichweite bist. Bei Sektorenfeuern gilt die Reichweite deines Sektors. Zusätzlich muss es im **AR-Umkreis** liegen. Ohne Reichweite gilt nur der AR-Umkreis.

Die Infokarte eines Leuchtfeuers nennt die Kennung und die Reichweite von deiner Position aus.

## Umkreis und Anzahl einstellen

Das Symbol mit den Schiebereglern oben in der AR-Ansicht öffnet drei Regler. Dieselben Regler findest du auch unter Menü > **Einstellungen**:

| Regler | Wirkung |
|---|---|
| **AR-Umkreis** | Wie weit entfernte POIs noch gezeigt werden. |
| **AR: POIs im Bild höchstens** | Wie viele POIs gleichzeitig im Bild stehen dürfen. |
| **AR-Routen-Sichtweite** | Wie weit voraus die Route bei der AR-Navigation gezeichnet wird. |

Vorgabe und Höchstwert legen die Administratoren fest. Unterhalb des Höchstwerts wählst du frei. Deine Wahl bleibt auf dem Gerät gespeichert.

## AR-Navigation entlang einer Route

1. Drücke lange auf eine Route (am Rechner: rechte Maustaste) und wähle **AR-Navigation**. Das geht mit jeder Route, die du siehst, auch mit fremden. Die Route wird dabei nicht verändert.
2. Die AR-Ansicht öffnet sich. Die Route liegt als Linie in ihrer Farbe auf dem Wasser. Sie beginnt bei dir und reicht in Fahrtrichtung bis zur **Sichtweite**. In der Ferne wird sie dünner und blasser.
3. Der **nächste Wegpunkt** ist mit einem Ring und seiner Nummer markiert.
4. Die **Navigationsleiste** unten zeigt:
   - einen **Pfeil**, der immer zum nächsten Wegpunkt zeigt, auch wenn er gerade nicht im Bild ist,
   - den **Kompasswinkel** und die **Entfernung** zum nächsten Wegpunkt,
   - **Wegpunkt 3/12** und die verbleibende Strecke bis zum Ziel.

Der Wegpunkt schaltet weiter, sobald du ihm auf 50 m nahe kommst. Kürzt du ab, springt er auf den Wegpunkt, der vor dir liegt. Das funktioniert wie beim [Garmin-Datenfeld](#chapter-garmin-uhr). Bist du weit von der Route entfernt, führt eine blasse Linie von dir zur Route. Am letzten Wegpunkt zeigt die Leiste **Ziel erreicht**.

Das **×** an der Navigationsleiste beendet die Routen-Navigation. Die POIs bleiben sichtbar. Öffnest du die AR-Ansicht später über das Menü oder das Kontextmenü der Karte, zeigt sie nur die POIs. Die Route erscheint nur, wenn du **AR-Navigation** im Kontextmenü einer Route wählst.

## Wenn etwas nicht stimmt

| Meldung oder Problem | Abhilfe |
|---|---|
| **Kein Kompass-Signal** oder POIs liegen deutlich daneben | Den Kompass kalibrieren: Smartphone ein paar Mal in einer liegenden Acht schwenken. Abstand zu Metall und Magneten halten (Bootsbeschläge, Handyhalterung mit Magnet). |
| **Warte auf die GPS-Position …** | Unter freiem Himmel warten, bis das Gerät einen GPS-Fix hat. |
| **Kein Zugriff auf die Kamera / den Standort** | In den Einstellungen des Browsers oder der App die Berechtigung erlauben. |

> **Hinweis:** Die AR-Ansicht ist eine Orientierungshilfe, kein Navigationsgerät. Kompass und GPS eines Smartphones können um einige Grad und Meter danebenliegen. Achte immer auf das, was du wirklich vor dir siehst.
