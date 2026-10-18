---
title: Wetter
keywords: Wetter, Wind, Böen, Regen, Welle, Gezeiten, Tide, Sonnenaufgang, Open-Meteo, DWD, DMI, SMHI, Windy, Regenradar, Windebene, Partikel, Windkarte, Niederschlagsprognose, OPERA, Beaufort
---

Für jeden Kartenpunkt zeigt YTAN eine Wettervorhersage für die nächsten **sieben Tage**, stündlich, mit Wind, Böen, Regen, Wellenhöhe und Gezeiten.

## So rufst du das Wetter auf

| Weg | So geht es |
|---|---|
| **Leerer Kartenpunkt** | Lang drücken (Rechtsklick) und **Wetterdaten für diesen Ort** wählen. |
| **POI** | Im Kontextmenü des POIs **Wetterdaten für diesen Ort**. |
| **Gebiet** | Im Kontextmenü des Gebiets **Wetterdaten für diesen Ort**. |
| **Dein Standort** | Menü > **Wetter für aktuellen Standort**, oder den Standort-Punkt antippen und **Wetter für Standort** wählen. |

Zusätzlich bietet das Kartenkontextmenü **Auf Windy öffnen** und **Regenradar auf Windy öffnen**: Das öffnet die Seite windy.com mit genau diesem Ort und dem aktuellen Zoom - der aktuelle Niederschlag lässt sich dort als Radar ansehen. Das Menü bietet außerdem **POI erstellen**.

## Regenradar auf der Karte

YTAN kann den Niederschlag direkt über die Karte legen: lang auf einen leeren Kartenpunkt drücken (Rechtsklick) und **Regenradar ein-/ausblenden** wählen. YTAN merkt sich die Einstellung. Das Radar gibt es für **Deutschland, Dänemark und Schweden** samt Ostsee, bis in die Zoomstufen, mit denen du paddelst.

| Teil | Bedeutung |
|---|---|
| **Abspielen** | Spielt die letzte Stunde und die Vorhersage als Film ab. |
| **Schieberegler** | Wählt einen Zeitpunkt. Der graue Teil ist gemessen, der **orange** Teil ist Vorhersage. |
| **Radar** / **Prognose +30 Min.** | Zeigt, ob das Bild gemessen oder vorhergesagt ist. |
| **Farbskala** | Regenrate in mm pro Stunde, von hellblau (Niesel) bis violett (Wolkenbruch). |
| **Stand** | Uhrzeit des neuesten Radarbilds. Die Messung ist etwa 10 Minuten alt, wenn sie ankommt. |
| **×** (oben rechts am Feld) | Blendet das Regenradar wieder aus, ebenso **Regenradar ein-/ausblenden** im Kartenkontextmenü. |

Die Vorhersage reicht **zwei Stunden** weit. Sie schiebt den gemessenen Regen in seiner Zugrichtung weiter, Schauer wachsen oder verschwinden darin nicht. Sie wird deshalb mit jeder Minute unsicherer - für die nächste halbe Stunde ist sie meist brauchbar, bei Gewittern schon früher nicht mehr. Steht **Radardaten veraltet** unten im Feld, kamen länger als 30 Minuten keine neuen Daten. Ohne Internetverbindung verschwindet das Radar und kommt mit der Verbindung zurück.

Die Radardaten stammen von **EUMETNET OPERA**, dem Verbund der europäischen Wetterdienste (Lizenz CC BY 4.0).

## Wind auf der Karte

Der Wind lässt sich auch direkt über die Karte legen: lang auf einen leeren Kartenpunkt drücken (Rechtsklick) und **Wind ein-/ausblenden** wählen. Kleine **Striche** ziehen mit dem Wind über die Karte - je stärker der Wind, desto schneller bewegen sie sich. Darunter färbt eine blasse **Farbfläche** die Windstärke ein. Die Ebene gibt es für die Gebiete, die auch das Regenradar abdeckt. Wind und Regenradar lassen sich nicht gleichzeitig einblenden: Das eine schaltet das andere aus.

| Teil | Bedeutung |
|---|---|
| **Abspielen** | Spielt die Vorhersagestunden als Film ab. |
| **Schieberegler** | Wählt die Stunde. Daneben stehen Wochentag und Uhrzeit und, wie weit sie in der Zukunft liegt (**+6 Std.**). |
| **Farbskala** | Windstärke in der Einheit, die du unter **Einstellungen** gewählt hast (Beaufort, m/s, km/h oder Knoten). |
| **Böen** | Nur wenn der Betreiber Böen mitliefert: färbt die Fläche nach Böen statt nach dem mittleren Wind. Die Striche zeigen immer den mittleren Wind. |
| **Modell** | Startzeit des Modelllaufs, aus dem die Daten stammen. Er ist beim Eintreffen etwa 3 Stunden alt. |
| **×** (oben rechts am Feld) | Blendet den Wind wieder aus, ebenso **Wind ein-/ausblenden** im Kartenkontextmenü. |

Die Striche **bewegen sich nur, solange du die Karte ansiehst**: Beim Ziehen und Zoomen pausieren sie, und nach einer Minute ohne Bewegung der Karte bleiben sie stehen (die Farbfläche bleibt). Das spart Akku. Ein Fingertipp auf das Feld oder jede Kartenbewegung startet sie wieder. Ohne Internetverbindung verschwindet die Ebene und kommt mit der Verbindung zurück.

Die Vorhersage ist ein **Modell mit etwa 2 km Raster** (in Deutschland und Umgebung, sonst etwa 6,5 km). Wind in engen Förden, hinter Landzungen und an Steilküsten kann deutlich davon abweichen, Böen und Düseneffekte kennt das Modell nur grob. Prüfe vor jeder Fahrt zusätzlich die amtlichen Seewetterberichte.

Die Winddaten stammen vom **Deutschen Wetterdienst** (Modelle ICON-D2 und ICON-EU, Wind in 10 m Höhe).

## Woher kommen die Daten?

| Daten | Quelle |
|---|---|
| Wetter in **Deutschland** | Deutscher Wetterdienst (DWD) |
| **Dänemark** | Dänisches Meteorologisches Institut (DMI) |
| **Norwegen** | MET Norway |
| **Frankreich** | Météo-France |
| **Niederlande** | KNMI |
| **Schweden** und die **Ostsee** außerhalb der Küstenstaaten | SMHI |
| alle anderen Orte | Open-Meteo (internationale Modelle) |
| **Wellen und Gezeiten** | Open-Meteo Marine |
| Sonnenauf- und -untergang | Open-Meteo |
| **Ortsname** in der Überschrift | OpenStreetMap / Nominatim |

YTAN wählt die Quelle automatisch nach dem Ort. Welche Quelle gerade verwendet wurde, steht unter der Überschrift: **Wetterdaten: DWD - 09.10.2026 / 18:47**. Mit dem **Kreispfeil** daneben lädst du die Vorhersage neu. Fehlen einzelne Stunden bei einer Quelle, ergänzt YTAN sie aus einer zweiten.

> **Hinweis:** Wellen- und Gezeitendaten gibt es nur auf dem Meer und in Küstennähe. An Binnengewässern steht dort „Hier keine Seegang-Daten verfügbar".

## Die Darstellung

![Die Wetter-Zeitleiste](wetter.jpg)

Von oben nach unten:

1. **Überschrift:** Wetter für den Ortsnamen, darunter die Koordinaten. Das **×** schließt die Anzeige.
2. **Tageskacheln:** Eine Kachel pro Tag mit Wettersymbol und Höchsttemperatur. Tippst du eine an, springt die Tabelle zu diesem Tag.
3. **Wochenkurve:** Die Temperatur der ganzen Woche als Kurve. Darunter zeigt ein **farbiger Balken** die Windstärke - je dunkler und intensiver, desto stärker. Liegt die Kurve um null Grad, markiert eine gestrichelte Linie den Gefrierpunkt.
4. **Stundentabelle:** Eine Spalte pro Stunde, nach rechts verschiebbar. Eine **gestrichelte Linie** zeigt die aktuelle Zeit. An jedem Tageswechsel stehen Datum, **Sonnenaufgang** (oben) und **Sonnenuntergang** (unten) mit Uhrzeit.

### Die Zeilen der Tabelle

| Zeile | Bedeutung |
|---|---|
| **Zeit** | Stunde (0-23), darunter das Wettersymbol (Sonne, Wolken, Regen, Gewitter, …). |
| **Temp. °C** | Temperatur, darunter klein die **gefühlte** Temperatur. |
| **Regen mm** | Niederschlag in dieser Stunde. Blau = Regen. |
| **Wind** | Mittlere Windgeschwindigkeit in einem **farbigen Kästchen**. Die Farbe folgt der Skala von Windy: Grün schwach, dann Gelb, Orange, Rot bis Violett bei Sturm. |
| **Böen** | Spitzenböen, ebenfalls farbig. |
| **Richtung** | Die Richtung, **aus der** der Wind kommt, als Himmelsrichtung (N, NNO, NO, … NNW) oder als **Pfeil** - die Pfeile zeigen dagegen, **wohin** der Wind weht. |
| **Welle m** | Wellenhöhe in Metern. |
| **Gezeiten** | Eine Kurve des Wasserstands. Hoch- und Niedrigwasser sind als Punkte mit ihrer **Uhrzeit** markiert. |

### Einheiten einstellen

Unter **Einstellungen** wählst du die **Windgeschwindigkeit** (Beaufort, m/s, km/h oder Knoten) und die Darstellung der **Windrichtung** (Kürzel oder Pfeil), siehe [Einstellungen](#chapter-einstellungen). In Beaufort zeigen „-" und „+" die Lage innerhalb der Stufe, zum Beispiel „4-", „4", „4+". Wer „nautisch" eingestellt hat, sieht Entfernungen in Seemeilen.

> **Hinweis:** Wetterdaten sind **Vorhersagen**. Wind und Wellen vor Ort können abweichen, besonders an der Küste und in engen Gewässern. Prüfe vor jeder Fahrt zusätzlich die amtlichen Seewetterberichte.
