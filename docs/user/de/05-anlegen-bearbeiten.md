---
title: Anlegen und Bearbeiten
keywords: Werkzeugleiste, Route anlegen, Wegpunkt, Autorouting, Uferabstand, Mindestabstand, Höchstabstand, Route über Wasser, Route teilen, Split, Routen zusammenfügen, Zusammenfügen, Verbinden, POI anlegen, POI verschieben, Sektor-Kennung, Windschatten-Indikator, WSI, Leuchtfeuer, Gebiet anlegen, Löschen, Fotos, öffentlich, privat
---

Mit der Werkzeugleiste legst du Routen, POIs und Gebiete an. Dazu musst du angemeldet sein. Alles Neue ist zunächst **privat**.

![Die Werkzeugleiste](werkzeugleiste.jpg)

## Routen

### Eine neue Route zeichnen

1. Tippe in der Werkzeugleiste auf **Route anlegen**. Das Symbol wird hervorgehoben.
2. Tippe auf die Karte: Jeder Tipp setzt einen **Wegpunkt** am Ende der Linie. Die bisherige Gesamtlänge steht oben in der Leiste.
3. Tippe auf **BEENDEN**, wenn die Route fertig ist.
4. Im Fenster **Route** gibst du die Daten ein (siehe unten) und tippst auf **Speichern**.

### Wegpunkte ändern

Beim Zeichnen und beim Bearbeiten einer bestehenden Route (Kontextmenü > **Route bearbeiten**) gilt:

| Aktion | So geht es |
|---|---|
| **Hinzufügen** | Auf die Karte tippen - der neue Punkt wird ans **Ende** der Route angehängt. |
| **Verschieben** | Einen Wegpunkt mit dem Finger oder der Maus an die neue Stelle ziehen. |
| **Entfernen** | Den Wegpunkt antippen. |

![Eine Route im Bearbeitungsmodus](route-bearbeiten.jpg)

**Mehrere Wegpunkte auf einmal löschen:**

- **Am Rechner:** Halte **Strg** (am Mac **Cmd**) gedrückt und ziehe mit der Maus ein Rechteck über die Karte - alle Wegpunkte darin sind ausgewählt (mit zusätzlich **Umschalt** kommen sie zur bisherigen Auswahl hinzu). **Strg + Klick** auf einen Wegpunkt wählt ihn aus oder ab. Mit **Entf** löschst du die ausgewählten Wegpunkte, nach einer Rückfrage.
- **Am Smartphone:** Drücke lange auf einen Wegpunkt und wähle **Mehrere Wegpunkte auswählen**. Dann wählt jedes Antippen eines Wegpunkts ihn aus oder ab; ein Tipp auf die leere Karte tut in dieser Zeit nichts. Mit **Strecke wählen** tippst du zwei Wegpunkte an und alles dazwischen ist ausgewählt.
- Unten erscheint eine Leiste mit der Anzahl, **Strecke wählen**, **Löschen** und einem Kreuz. Das Kreuz oder **Esc** beendet die Auswahl; auch ein einzelner Klick auf die leere Karte hebt sie auf (er setzt dabei keinen neuen Wegpunkt). Mindestens zwei Wegpunkte müssen übrig bleiben.

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

### Autorouting - die Route über Wasser führen

Hast du nur die Wegpunkte gesetzt, kann YTAN die Strecke dazwischen **selbst über Wasser legen**: Abschnitte über Land werden umfahren, und die Route hält einen Abstand zum Ufer ein. Die gesetzten Wegpunkte bleiben dabei, wo sie sind; gesucht wird eine möglichst **sichere und kurze** Strecke dazwischen.

1. Öffne das Kontextmenü der Route (lange drücken, am Rechner Rechtsklick) und wähle **Autorouting** (der zweite Eintrag, unter **Route bearbeiten**). Du musst die Route bearbeiten dürfen.
2. Liegt der **Start oder das Ende an Land** oder außerhalb des Gebiets, für das YTAN Gewässerdaten hat (Europa), erscheint nur ein Hinweis. Der Dialog öffnet sich dann nicht.
3. Stelle im Dialog ein:
   - **Abstand zum Ufer einhalten** - Haken weg, dann meidet YTAN nur das Land.
   - **Mindestabstand zum Ufer** in Metern (0 = nur Land meiden).
   - **Höchstabstand zum Ufer** in Metern (0 = unbegrenzt).
4. Tippe auf **Autorouting starten**. Die Berechnung dauert einige Sekunden.

Das Ergebnis erscheint im **Routen-Editor**, wie bei **Route bearbeiten**. Du kannst Wegpunkte noch verschieben und speicherst mit dem üblichen Button. **Abbrechen** lässt die Route unverändert, bis dahin ist nichts gespeichert.

So arbeitet es:

- **Mindestabstand**: Wo es eng wird (Meerenge, Schären), wird er unterschritten, statt aufzugeben. Dann erscheint ein Hinweis. In der Nähe von Start und Ende gilt er nicht, denn dort liegt meist ein Strand oder Steg.
- **Höchstabstand**: Er gilt nur, **wenn es möglich ist**. Die Route bleibt in diesem Abstand zur Küste, solange der Umweg vertretbar bleibt. Offenes Wasser, das sich nicht umgehen lässt, wird auf dem kürzesten Weg gequert.
- Teile der Route, die schon über Wasser führen und die Abstände einhalten, bleiben unverändert.
- Findet YTAN für einen Abschnitt keinen Weg über Wasser, bleibt er unverändert, und du bekommst einen Hinweis.
- Nach der Berechnung zeigt ein Hinweis, wie viele Abschnitte geändert wurden und wie sich die Länge ändert. Prüfe das Ergebnis vor dem Speichern.

#### Weitere Wege zum Autorouting

Das Kontextmenü der Route ist nicht der einzige Weg. Die Einstellungen aus dem Dialog (Abstände zum Ufer) gelten überall.

- **Beim Zeichnen oder Bearbeiten** gibt es in der oberen Leiste (neben **BEENDEN**) das Kästchen **Autorouting**. Ist es angehakt, verbindet YTAN jeden neuen letzten Wegpunkt automatisch **über Wasser** mit dem vorherigen, ohne Dialog. Ein Segment kürzer als 60 m bleibt, wie du es gesetzt hast. Auf schmalen Smartphones rutscht das Kästchen in eine zweite Zeile unter der Leiste. Während YTAN rechnet, zeigt ein Hinweis mit Ladesymbol die Berechnung an und die Karte nimmt keine Eingaben an: Du kannst in der Zeit keine Wegpunkte setzen oder verschieben.
- **Ein einzelner Abschnitt**: Drücke beim Bearbeiten lange auf einen inneren Wegpunkt (am Rechner Rechtsklick) und wähle **Strecke davor auf Wasser legen** oder **Strecke danach auf Wasser legen**.
- **Beim Speichern**: Führt die gezeichnete oder geänderte Route auf mehr als 150 m über Land, fragt YTAN vor dem Speichern, ob sie **auf Wasser gelegt** werden soll. Mit **Nein, so speichern** wird sie unverändert gespeichert. Aufgezeichnete GPS-Tracks werden nicht gefragt.
- **Im Bearbeiten-Fenster** der Route (auch direkt nach einer GPS-Aufzeichnung) steht der Knopf **Auf Wasser legen**; er legt die ganze Linie über Wasser, mit dem Dialog wie oben.
- **Beim GPX-Import**: Mit dem Haken **Strecken über Land auf Wasser legen** werden die importierten Routen sofort über Wasser gelegt. Routen, deren Start oder Ende nicht am Wasser liegt, bleiben unverändert.

Die Werte, die du im Dialog einstellst, merkt sich YTAN für dich. Du kannst sie auch im [Profil](#chapter-profil) unter **Autorouting** ändern; ein leeres Feld bedeutet den Standard des Betreibers.

Grenzen: Routen bis **300 km**. Das Autorouting kennt Meer, Flüsse, Kanäle und **Seen ab 10 km²**. Kleine Binnenseen, Teiche und breite Flussmündungen gelten als Land; liegt dort Start oder Ende, meldet YTAN „an Land". Häfen und Marinas erkennt es nur, wenn sie in den Gewässerdaten stehen.

### Eine Route teilen (aufspalten)

Eine Route lässt sich an einem Wegpunkt in **zwei Routen** zerlegen, zum Beispiel um aus einer langen Strecke Tagesetappen zu machen:

1. Öffne die Route zum Bearbeiten (Kontextmenü > **Route bearbeiten**).
2. Drücke auf einen **inneren** Wegpunkt lange (am Rechner: Rechtsklick).
3. Wähle im Menü **Route hier in zwei Routen teilen**. Das Menü schließt sich, wenn du auf die Karte tippst.

Die erste Route behält den Anfang bis zu diesem Punkt und heißt „… (Teil 1)", die zweite bekommt den Rest als „… (Teil 2)". Beschreibung, Farbe, Fotos und Sichtbarkeit werden kopiert; in Touren steht die neue Route direkt hinter der ursprünglichen.

Die Route muss dazu bereits gespeichert sein.

### Zwei Routen zusammenfügen

Das Gegenstück zum Teilen: aus zwei Routen wird eine, zum Beispiel wenn du eine Strecke in zwei Etappen gezeichnet hast.

1. Öffne das Kontextmenü der ersten Route (lange drücken, am Rechner Rechtsklick) und wähle **Mit anderer Route zusammenfügen**.
2. Tippe auf die zweite Route. Mit dem **x** im Hinweis unten (oder Escape) brichst du ab.
3. Wähle im Dialog, welche Route **erhalten bleibt**, und passe den **Namen** an. Vorgeschlagen wird „Name 1 + Name 2" - zuerst die Route, mit der die zusammengefügte Strecke beginnt.
4. Tippe auf **Zusammenfügen**.

So werden die beiden verbunden:

- Verbunden werden immer die beiden **Endpunkte** (Anfang oder Ende), die am nächsten beieinander liegen.
- Liegen sie genau aufeinander, wird daraus **ein** gemeinsamer Wegpunkt.
- Liegen sie sehr dicht beieinander (bis 10 m, der Wert ist im Admin-Bereich einstellbar), ersetzt sie **ein Punkt auf halber Strecke**.
- Liegen sie weiter auseinander, kommt eine **Verbindungsstrecke** dazu.
- Die erhaltene Route behält ihre **Richtung**; läuft die andere Route andersherum, wird sie umgedreht.

Die erhaltene Route behält Beschreibung, Farbe und Sichtbarkeit. Die andere Route wird **gelöscht**: ihre Fotos werden übernommen (zusammen höchstens 5), und in ihren Touren tritt die erhaltene Route an ihre Stelle. Du musst beide Routen bearbeiten dürfen, und beide müssen bereits gespeichert sein.

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

### Einen POI verschieben

Liegt ein eigener POI an der falschen Stelle, verschiebst du ihn, ohne das Bearbeiten-Fenster zu öffnen:

1. Tippe im Infofenster des POIs auf das **Kreuzpfeil-Symbol** (unten links) oder wähle im Kontextmenü **POI verschieben**.
2. Ziehe das Symbol an die richtige Stelle.
3. Bestätige oben in der zweiten Leiste mit **Speichern** (auf schmalen Bildschirmen ein Haken) oder verwirf mit **Abbrechen** (Kreuz).

Nach dem Verschieben berechnet YTAN den Abstand zum Wasser neu.

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

Bei **Leuchtfeuer** (Leuchttürme, Seezeichen) trägst du die **Kennung** ein, zum Beispiel „Iso WRG 6s". Für die farbigen Sektoren gibt es das Feld **Sektor-Kennung**. YTAN zeichnet daraus die Lichtsektoren auf die Karte: über **Zeige Sektorlicht** im Infofenster oder **Sektorleuchtfeuer anzeigen** im Kontextmenü des Leuchtfeuers.

## Gebiete

1. Werkzeugleiste > **Gebiet anlegen**.
2. Tippe nacheinander die **Ecken** der Fläche auf die Karte. Wegpunkte lassen sich wie bei Routen verschieben und entfernen.
3. Tippe auf **BEENDEN**.
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
