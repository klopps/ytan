---
title: Garmin-Uhr
keywords: Garmin, Uhr, Datenfeld, Connect IQ, Sideloading, SET-Datei, Uhr-Schlüssel, fenix, Wegpunkt, ETA, Navigation
---

Das YTAN-Datenfeld zeigt dir auf deiner Garmin-Uhr **während einer Aktivität** den Weg entlang deiner Route: Richtung und Entfernung zum nächsten Wegpunkt, Fortschritt, voraussichtliche Ankunftszeit.

## Kurzbeschreibung

Du wählst in YTAN eine Route aus und sendest sie an die Uhr. Das **Datenfeld „YTAN Waypoint"** auf der Uhr navigiert dich von Wegpunkt zu Wegpunkt:

- ein **Dreieck**, das zum nächsten Wegpunkt zeigt (dazu der Kompasswinkel),
- die **Entfernung** zum nächsten Wegpunkt,
- die **Nummer** des Wegpunkts (z. B. 3/12),
- die **ETA** - Ankunftszeit am Ziel und die verbleibende Zeit (z. B. „ETA 15:42 (1:23 h)"),
- Uhrzeit und Puls,
- am Ziel eine **Zusammenfassung** mit Gesamtstrecke, Gesamtzeit und Fahrzeit.

Das Datenfeld kann nichts eingeben und hat keinen eigenen Zugang zu YTAN. Deshalb braucht es einen **Weg, die Route zu bekommen**:

| Weg | Wie |
|---|---|
| **Über die Android-App** | Die App sendet die Route direkt an die Uhr, innerhalb weniger Sekunden. Die Uhr braucht dafür einen Bluetooth-Kontakt zu **Garmin Connect** auf dem Handy. |
| **Über den Uhr-Schlüssel** | Die Uhr holt die Route selbst von YTAN, beim Start der Aktivität und danach alle 5 Minuten - über das Handy. Das funktioniert auch mit der Webseite (ohne App). |

Beide Wege lassen sich kombinieren.

> **Gut zu wissen:** Ein Datenfeld läuft nur **während einer Aktivität**. Eine Route, die du sendest, bevor du die Aktivität startest, kommt beim Start der Aktivität an (Schlüssel-Weg) oder bleibt unzugestellt, wenn nur die App sendet. Wiederhole das Senden dann bei laufender Aktivität.

## Unterstützte Uhren

| Uhr | Unterstützt |
|---|---|
| fenix 7 / 7S / 7X (auch Pro) | ja |
| fenix 6 Pro / 6S Pro / 6X Pro | ja |
| Forerunner 245 **Music** | ja |
| fenix 6 / 6S ohne Pro, Forerunner 245 ohne Music | **nein** (zu wenig Speicher für das Datenfeld) |

## Einrichtung (Sideloading)

Das Datenfeld ist nicht im Connect-IQ-Store. Du installierst es von Hand auf der Uhr („Sideloading"). Das geht in vier Schritten.

### 1. Das Datenfeld für deine Uhr besorgen

Du brauchst die Datei **`ytan-<Uhrmodell>.prg`** (zum Beispiel `ytan-fenix7x.prg`). Die Administratoren von YTAN stellen sie bereit - es gibt **eine Datei pro Uhrmodell**, die für alle Nutzer gleich ist. Die persönliche Verbindung zu deinem Konto kommt erst im nächsten Schritt in einer kleinen Zusatzdatei.

### 2. Auf die Uhr kopieren

1. Schließe die Uhr per USB an den Rechner an. Sie erscheint wie ein USB-Stick.
2. Kopiere die `.prg`-Datei in den Ordner **`GARMIN\Apps`** der Uhr.
3. Trenne die Uhr. Sie übernimmt die Datei, die dabei aus dem Ordner verschwindet. Das ist normal.

### 3. Das Datenfeld in eine Aktivität einbauen

1. Öffne auf der Uhr die Aktivität, bei der du navigieren willst (z. B. „Kajak" oder „Paddeln"), und halte die Taste für die **Einstellungen** der Aktivität.
2. Gehe zu **Datenbildschirme** und wähle einen Bildschirm (oder lege einen neuen an).
3. Wähle ein Datenfeld und suche in der Kategorie **Connect IQ** den Eintrag **YTAN Waypoint**.

Der Aufbau der Menüs ist je nach Modell leicht verschieden.

### 4. Den Uhr-Schlüssel und die Einstellungsdatei (.SET)

Der **Uhr-Schlüssel** ist dein persönliches Kennwort, mit dem sich die Uhr gegenüber YTAN ausweist. Du bringst ihn in einer **Einstellungsdatei (`.SET`)** auf die Uhr.

1. In YTAN: Menü > **Profil** > **Garmin-Uhr** > **Uhr-Schlüssel erzeugen**.

   ![Die Garmin-Uhr im Profil](profil-uhr.jpg)

2. Tippe auf **Einstellungsdatei (.SET) herunterladen**. Die Datei heißt `ytan-ReplaceByWatchName.SET`. Der Schlüssel selbst wird nur einmal angezeigt; die Einstellungsdatei bleibt dagegen erhalten, bis du einen neuen Schlüssel erzeugst.
3. **Starte das Datenfeld auf der Uhr einmal** (Aktivität starten, dann beenden). Dabei legt die Uhr im Ordner **`GARMIN\Apps\SETTINGS`** eine Einstellungsdatei an, z. B. `ytan-fenix7pro.SET`. **Merke dir diesen Namen.** (Er stammt von der allerersten Installation und kann vom Modellnamen der heutigen `.prg` abweichen.)
4. Verbinde die Uhr per USB und kopiere deine `ytan-ReplaceByWatchName.SET` nach `GARMIN\Apps\SETTINGS`. **Benenne sie vorher so um**, wie die vorhandene Datei dort heißt, und lass sie die vorhandene **überschreiben**.
5. Trenne die Uhr. Beende vorher eine laufende Aktivität - die Einstellung wird beim **Start** der Aktivität gelesen.

> **Wichtig:** Erzeugst du in YTAN einen **neuen Schlüssel**, wird der alte ungültig. Das Datenfeld zeigt dann **Watch key?**. Lade die neue `.SET`-Datei herunter und kopiere sie wie oben auf die Uhr.

## Eine Route auf die Uhr senden

1. Tippe die Route auf der Karte lange an (Rechtsklick) und wähle **An Garmin-Uhr senden**. Der Eintrag erscheint nur, wenn du eine Uhr eingerichtet hast (Schlüssel erzeugt oder in der Android-App).
2. YTAN meldet, dass die Route gesendet wurde. Mit der **Android-App** ist sie nach wenigen Sekunden auf der Uhr; sonst übernimmt die Uhr sie beim **Start** der Aktivität, bei laufender Aktivität spätestens nach etwa 5 Minuten.
3. Das Datenfeld wechselt auf die neue Route und beginnt beim ersten Wegpunkt. Öffne dazu gegebenenfalls den Datenbildschirm mit dem YTAN-Feld.

Welche Route gerade auf der Uhr liegt, steht unter Profil > **Garmin-Uhr** („Route auf der Uhr: …"). **Route von der Uhr entfernen** nimmt sie wieder weg.

## Die Anzeige auf der Uhr

Über dem Datenfeld steht in der ersten Zeile **YTAN**. Der Zusatz sagt dir, wie es dem Abgleich geht:

| Anzeige | Bedeutung |
|---|---|
| **YTAN** | Alles in Ordnung, die Route ist aktuell. |
| **YTAN ?** | Es gab noch keinen Abgleich. Warte bis zu 5 Minuten bei laufender Aktivität. |
| **YTAN -104** (oder eine andere Zahl mit Minus) | Der letzte Abgleich scheiterte, meist weil die Uhr das Handy nicht erreicht (Bluetooth oder Garmin Connect aus). |
| **YTAN 401** / **Watch key?** | YTAN lehnt den Schlüssel ab: veraltet oder falsch. Lade die aktuelle `.SET`-Datei neu auf die Uhr. |

Statusmeldungen im Feld:

| Meldung | Bedeutung |
|---|---|
| **No route** | Es liegt keine Route auf der Uhr. Sende eine Route. |
| **No GPS** | Die Uhr hat noch keine Position. Warte, bis sie Satelliten gefunden hat. |
| **Finish reached** | Du hast das Ziel erreicht. Danach zeigt die Uhr die Zusammenfassung. |

## Farben und Geschwindigkeit anpassen

- **Farben:** Unter Profil > **Garmin-Uhr** > **Farben auf der Uhr** wählst du die Farben für Richtung, Entfernung, übrige Texte und das Nord-Dreieck, getrennt für dunklen und hellen Uhr-Hintergrund. Die Uhr übernimmt sie beim nächsten Abgleich. Uhren mit wenigen Farben stellen sie gröber dar.
- **Standard-Geschwindigkeit:** Siehe [Profil](#chapter-profil). Mit ihr rechnet die ETA anfangs.

## Der Schlüssel abschalten

Unter Profil > **Garmin-Uhr** > **Kopplung aufheben** erhält die Uhr keine Routen mehr über den Schlüssel. Das installierte Datenfeld kannst du auf der Uhr wie jede andere Connect-IQ-App löschen.
