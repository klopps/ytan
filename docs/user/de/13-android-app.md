---
title: Android-App
keywords: Android, App, Installation, APK, Track aufzeichnen, Aufzeichnung, GPS, Offline, Ausstehende Änderungen, Update, Berechtigungen
---

Die YTAN-App für Android enthält die komplette Webseite und kann zusätzlich, was ein Browser nicht kann: deinen **Track im Hintergrund aufzeichnen**, **ohne Netz arbeiten** und Routen direkt an die **Garmin-Uhr** senden.

## Installation

YTAN ist nicht im Play Store; die App wird direkt von der Seite installiert.

1. Öffne YTAN im **Chrome-Browser auf deinem Android-Gerät**.
2. Tippe im Menü auf **Installiere YTAN-App** (der Eintrag erscheint nur auf Android und nur, solange die App noch nicht installiert ist).
3. Lade die Datei herunter und öffne sie. Android fragt, ob dieser Quelle die Installation erlaubt ist - bestätige das für Chrome.
4. Starte die App und melde dich wie gewohnt an.

### Updates

Gibt es eine neue Version, zeigt die App beim Start einen Hinweis mit **Aktualisieren**. Im Menü steht außerdem **Installiere Update auf v…** hervorgehoben. Ein Tipp lädt das Update herunter und startet die Installation von Android. Ist die App veraltet, kannst du bis zum Update nichts mehr ändern - Ansehen funktioniert weiter.

## Track aufzeichnen

Die App zeichnet deine Fahrt per GPS auf - **auch bei gesperrtem Bildschirm**. Am Ende wird daraus eine Route.

### Vorbereiten

Beim ersten Start der Aufzeichnung fragt Android nach Berechtigungen. Damit die Aufzeichnung bei gesperrtem Bildschirm nicht abbricht, brauchst du:

- **Standort: „Immer erlauben"** (nicht nur „Während der Nutzung der App"),
- **Benachrichtigungen erlaubt** (sonst beendet Android die Aufzeichnung im Hintergrund vorzeitig),
- die **Standortdienste (GPS)** des Geräts eingeschaltet.

Fehlt etwas, zeigt YTAN eine Warnung **Berechtigungen erforderlich** mit der Schaltfläche **Einstellungen öffnen** - dort wählst du unter „Berechtigungen" > „Standort" **Immer erlauben**.

### Aufzeichnen

1. Menü > **Track aufzeichnen**.
2. Wähle die **Genauigkeit**:
   | Stufe | Wann |
   |---|---|
   | **Präzise** | Detailreicher Track, verbraucht mehr Akku. |
   | **Ausgewogen** | Guter Kompromiss. |
   | **Akkusparend** | Weniger Punkte, schont den Akku bei langen Fahrten. |
3. Tippe auf **Aufzeichnung starten**. Oben erscheint die Marke **Aufzeichnung läuft**; eine Benachrichtigung „YTAN zeichnet auf" bleibt, solange es läuft. Wechsle beliebig die App oder sperre den Bildschirm.
4. Mit **Pause** hältst du an (zum Beispiel bei einer Rast), mit **Fortsetzen** geht es weiter. Dauer, Distanz und Zahl der Punkte siehst du im Menü.
5. Tippe am Ende auf **Stopp & überprüfen**.

> **Tipp:** Drücke die Marke **Aufzeichnung läuft** lange, um das Aufzeichnungsmenü zu öffnen, ohne erst das Hauptmenü zu bemühen.

### Speichern

Nach **Stopp & überprüfen** siehst du die aufgezeichnete Linie und das gewohnte Route-Fenster. Gib einen Namen ein und tippe auf **Speichern**. Die Route trägt Aufzeichnungszeit und Dauer; wer das Recht dazu hat, sieht sie im Infofenster.

- **Verwerfen** löscht die Aufzeichnung unwiderruflich.
- Bei **zu wenigen Punkten** lässt sich keine Route speichern.
- **Nicht angemeldet?** Dann bleibt die Aufzeichnung auf dem Gerät erhalten, bis du dich anmeldest.
- **Kein Netz?** Der Track wird **lokal gespeichert** und automatisch hochgeladen, sobald wieder Netz da ist. Unter **Wartende Tracks** kannst du ihn von Hand mit **Hochladen** oder **Alle hochladen** übertragen.
- **Unterbrochen** (Akku leer, App beendet)? Beim nächsten Start setzt YTAN die Aufzeichnung fort („Unterbrochene Aufzeichnung fortgesetzt").

## Ohne Netz arbeiten

YTAN merkt sich die zuletzt geladenen Karteninhalte (POIs, Routen, Gebiete, Touren) auf dem Gerät, sodass die Karte auch bei einem Start ohne Netz nicht leer ist. Das gilt in der App und - eingeschränkt - auch im Browser. Legst du ohne Netz POIs, Routen oder Gebiete an oder änderst oder löschst sie, speichert YTAN das auf dem Gerät und überträgt es später.

Alles, was noch nicht auf dem Server ist, siehst du unter Menü > **Ausstehende Änderungen** (der Eintrag erscheint nur, wenn es etwas zu übertragen gibt). Jeder Eintrag sagt, ob er **neu angelegt**, **geändert** oder **gelöscht** wurde. Mit **Hochladen** überträgst du einen Eintrag, mit **Alle synchronisieren** alle. Ein Eintrag lässt sich auch verwerfen.

### Konflikte

Wurde derselbe Eintrag inzwischen auch auf dem Server verändert (zum Beispiel von jemand anderem), markiert YTAN ihn als **Konflikt**. Tippe auf **Lösen …** und entscheide:

- **Meine Version behalten** - deine Änderung überschreibt die Serverversion.
- **Server-Version behalten** - deine Änderung wird verworfen.

## Route an die Garmin-Uhr senden

Mit der App gelangt eine Route innerhalb weniger Sekunden auf die Uhr, siehe [Garmin-Uhr](#chapter-garmin-uhr).
