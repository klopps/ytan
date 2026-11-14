---
title: Routencheck
keywords: Routencheck, KI, Einschätzung, Ampel, Startzeit, Wind, Böen, Welle, Gewitter, Dunkelheit, Strömung, Fluss, Lee, ablandig, Erfahrungsstufe, Einsteiger, Experte, Claude, Anthropic, Tageslimit
---

Der **Routencheck** prüft eine Route für einen Tag deiner Wahl: Er sagt, ob die Fahrt mit dem vorhergesagten Wetter **gut fahrbar**, **nur mit Vorsicht** oder **nicht empfohlen** ist, welche Startzeiten sich eignen und welche Abschnitte kritisch werden. Dazu schreibt eine **KI** eine kurze Einschätzung in Worten.

> **Wichtig:** Der Routencheck ist eine **Planungshilfe, keine Sicherheitsgarantie**. Er kennt nur die Wettervorhersage und die Karte, nicht das Wasser vor Ort. Prüfe vor jeder Fahrt zusätzlich die amtlichen Seewetterberichte und entscheide selbst.

## Wer den Routencheck nutzen kann

Den Routencheck gibt es nur, wenn die Administratoren ihn eingeschaltet und dir das Recht dazu gegeben haben. Ohne dieses Recht siehst du die Schaltfläche gar nicht. Für jede Route, die du sehen kannst, lässt er sich aufrufen, also für deine eigenen und für öffentliche.

## Den Routencheck aufrufen

| Weg | So geht es |
|---|---|
| **Infofenster der Route** | Route antippen, dann **Routencheck**. |
| **Kontextmenü der Route** | Lang auf die Route drücken (Rechtsklick), dann **Routencheck**. |

Beim ersten Aufruf erklärt ein Hinweis, welche Daten an die KI gehen (siehe unten). Mit **Verstanden** geht es weiter; YTAN fragt danach nicht mehr.

Der Routencheck öffnet sich **ohne weitere Eingaben**: Vor 10 Uhr prüft er heute ab der nächsten vollen Stunde, später den morgigen Tag mit Start um **10 Uhr**. Er rechnet mit deiner [Standard-Geschwindigkeit](#chapter-profil) (ohne Eintrag 6 km/h) und fährt die Route in der Richtung, in der sie gezeichnet ist.

## Das Infofeld

Auf dem Handy erscheint das Infofeld unten über der Karte, am Rechner rechts daneben. Die Karte bleibt sichtbar und bedienbar. Auf dem Handy ziehst du das Feld am Griff oben größer oder kleiner; ein Tipp auf den Griff wechselt zwischen klein und groß. Das **×** oben rechts (oder die Escape-Taste) schließt es.

![Der Routencheck auf dem Handy: Infofeld unten, Route in Ampelfarben](routencheck.jpg)

Von oben nach unten:

1. **Ampel:** Grün **Gut fahrbar**, Gelb **Mit Vorsicht**, Rot **Nicht empfohlen**, Grau **Keine Daten**. Darunter steht der wichtigste Grund, etwa „Böen 9 m/s (ab 8)“, und die Fahrt im Überblick: Start und Ankunft, Länge, Geschwindigkeit.
2. **Startzeit:** Eine Leiste mit den Stunden des Tages, jede in der Ampelfarbe, die die Fahrt bei Start zu dieser Stunde hätte. So siehst du auf einen Blick, wann sich der Aufbruch lohnt. Ein Tipp auf eine Stunde wählt sie als Startzeit.
3. **Tag:** **Heute**, **Morgen** und die fünf Tage danach.
4. **−1 h / +1 h** verschieben die Startzeit, **Umkehren** fährt die Route in der Gegenrichtung. Das lohnt sich oft: Gegenwind auf dem Hinweg ist etwas anderes als auf dem Rückweg.
5. **Strömung** (nur bei Routen auf einem Fluss, siehe unten).
6. **Erfahrungsstufe:** **Einsteiger**, **Erfahren** oder **Experte**. Einsteiger bekommen strengere Grenzen, Experten großzügigere. YTAN merkt sich deine Wahl für das nächste Mal.
7. **KI-Einschätzung:** Ein kurzer Text mit Fazit, guten Startzeiten, den kritischen Abschnitten und Ausweichmöglichkeiten. Er erscheint ein paar Sekunden nach der Ampel. Darunter steht, von wann die Einschätzung stammt.
8. **Abschnitte:** Die Route in Stücken von etwa 3 km, jedes mit Ampelfarbe, Kilometern, Uhrzeit und Gründen. Ein Tipp zoomt die Karte auf diesen Abschnitt.
9. **Anlandestellen und Gefahren in der Nähe:** Zeltplätze, Anlandestellen, Shelter, Umtragestellen und Gefahrenstellen bis 2 km neben der Route, mit ihrer Lage an der Strecke. Ein Tipp zeigt den Ort auf der Karte. Deine privaten POIs sind dabei, die anderer Nutzer nicht.

Solange das Infofeld offen ist, färbt YTAN die Route auf der Karte **abschnittsweise in den Ampelfarben**. Beim Schließen verschwindet die Färbung wieder.

Jede Änderung (Tag, Startzeit, Richtung, Strömung, Stufe) rechnet sofort neu.

## Wie die Ampel entsteht

Die Farben **rechnet YTAN selbst nach festen Regeln**, nicht die KI. Die KI erklärt sie nur in Worten. Für jeden Abschnitt schaut YTAN auf die Stunde, in der du ihn voraussichtlich befährst, und prüft:

| Merkmal | Wird gelb oder rot, wenn … |
|---|---|
| **Wind** und **Böen** | der mittlere Wind oder die Böen über den Grenzen liegen (Standard: Wind ab 5 m/s gelb, ab 8 m/s rot). |
| **Welle** | die Wellen hoch sind. Die Welle des Wettermodells wird durch eine eigene Schätzung aus Wind und freier Wasserfläche begrenzt, weil das Modell in Förden und Schären oft zu hoch liegt. |
| **Gewitter** | ein Gewitter vorhergesagt ist (immer rot). |
| **Dunkelheit** | du in der Dämmerung (gelb) oder nachts (rot) unterwegs wärst. |
| **Kaltes Wasser** | das Wasser kälter als 10 °C ist (gelb). |
| **Ablandiger Wind** | der Wind vom nahen Land aufs offene Wasser weht. Wer dann kentert oder müde wird, treibt hinaus (mindestens gelb). |
| **Wind und Welle von der Seite** | Wellen quer zur Fahrtrichtung laufen (eine Stufe höher). |
| **Strömung** | ein Fluss dir entgegenkommt (siehe unten). |

Dazu berücksichtigt YTAN, wie viel **offenes Wasser** in Windrichtung liegt: Im **Lee** einer Küste oder Insel gelten großzügigere Grenzen, über eine weite freie Wasserfläche strengere. Gegenwind macht dich langsamer, das verschiebt die Ankunftszeit. Fehlen für eine Stunde Wetterdaten, bleibt der Abschnitt **grau**; ein Hinweis nennt das.

Die Grenzwerte legen die Administratoren fest, die Erfahrungsstufe verschiebt sie für dich.

## Strömung auf Flüssen

Liegt eine Route auf einem Fluss, rechnet der Routencheck mit seiner **Strömung**: Flussab bist du schneller, flussauf langsamer, und eine starke Strömung gegen die Fahrtrichtung färbt die Ampel gelb oder rot. Die Fließrichtung kennt YTAN aus den Kartendaten von OpenStreetMap. Auf **Kanälen** rechnet er ohne Strömung.

Wie schnell der Fluss tatsächlich fließt, weiß YTAN nicht. Es nimmt einen Wert an, den die Administratoren festlegen (meist 2 km/h). Mit **−** und **+** neben **Strömung** änderst du ihn in Schritten von 0,5 km/h, zum Beispiel bei Hochwasser. Die echte Strömung hängt vom Wasserstand ab und ist in Kurven und Engstellen stärker.

> **Hinweis:** Tidegewässer (zum Beispiel die Elbe unterhalb von Hamburg) kennt der Routencheck noch nicht als solche. Dort kehrt sich die Strömung mit den Gezeiten um, der Routencheck rechnet aber mit der normalen Flussströmung.

## Wenn die KI-Einschätzung fehlt

Die Ampel, die Startzeiten und die Abschnitte kommen immer. Nur der Text kann fehlen:

| Meldung | Bedeutung |
|---|---|
| **Tageslimit erreicht** | Pro Tag gibt es eine begrenzte Zahl KI-Einschätzungen. Unten im Feld steht, wie viele dir heute noch bleiben. |
| **Gerade nicht verfügbar** | Die KI hat nicht geantwortet. **Erneut versuchen** fragt noch einmal. |
| **Keine Wetterdaten** | Für den Tag gibt es keine Vorhersage, es gibt nichts zu bewerten. |

Gleiche Anfragen kurz hintereinander (zum Beispiel wieder zurück auf die vorige Startzeit) beantwortet YTAN aus einem Zwischenspeicher. Sie zählen nicht zum Tageslimit.

## Welche Daten an die KI gehen

Die Einschätzung schreibt **Claude** von der Firma Anthropic in den USA. YTAN schickt dafür:

- Angaben zur Route: Abschnitte, Länge, Kurs, Uhrzeiten - **keine Koordinaten** und nicht den Namen der Route,
- die Wetterwerte und die Ampel-Gründe,
- die Namen der Orte in der Nähe, auch deiner privaten POIs.

Dein Name und deine E-Mail-Adresse werden **nicht** übertragen. Mehr dazu steht in der Datenschutzerklärung.
