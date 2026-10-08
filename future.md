# Zukünftige Ideen

Punkte, die bewusst zurückgestellt sind - weder offen (todo.md) noch erledigt (done.md). Ein Punkt wandert zurück nach todo.md, sobald er angegangen wird.

## Universelle Garmin-App zur Routennavigation

Es soll untersucht werden, ob die Funktionalität der Garmin-App zur Routennavigation nicht universell für Garmin-Watches OHNE YTAN-Backend verwendet werden kann. Zum Austausch der Routeninformationen soll das Format GPX 1.1 verwendet werden. Es ergeben sich u. a. folgende Fragen:
- Kann GPX 1.1 auch als Austauschformat zwischen YTAN und Garmin-App dienen?
- Wie könnten die GPX-Daten ohne YTAN an die Garmin-App übermittelt werden?

### Erkenntnisse der ersten Untersuchung (2026-10-08)
Quelle: lokale Connect-IQ-SDK-Doku 9.2.0 (`%APPDATA%\Garmin\ConnectIQ\Sdks\...\doc\Toybox`), Geräte-`compiler.json` und der Code in `watch/`/`src/`. Noch kein Code, kein Spike.

- **Heutiges Format:** `src/Service/WatchRoutePayload.php` liefert eine flache Integer-Liste (lat/lng × 1e5) mit höchstens 250 Punkten, vereinfacht per Douglas-Peucker (`GeometryService::simplifyPolyline`), dazu `v`/`n`/`u` sowie `c` (Farben) und `s` (Geschwindigkeit). Grund dafür ist die Speichergrenze.
- **Speichergrenzen** (`%APPDATA%\Garmin\ConnectIQ\Devices\*\compiler.json`): fenix 7X/7 Pro: Datenfeld 256 KB, Hintergrundprozess **64 KB**. fenix 6 Pro: Datenfeld 128 KB, Hintergrund noch nicht nachgesehen.
- **YTAN kann heute kein GPX**, weder Import noch Export (grep in `src`/`public/js`/`templates` leer).
- **Native Garmin-Kurse sind für Connect IQ nicht lesbar:**
  - `makeWebRequest` mit `HTTP_RESPONSE_CONTENT_TYPE_GPX` lädt GPX/FIT herunter und legt es als nativen Kurs ab.
  - `PersistedContent.Course` hat aber nur `getId/getName/remove/toIntent`, keine Punkte.
  - Für die Wege "GPX per Garmin Connect als Kurs aufs Gerät" und "Uhr lädt GPX als Kurs" gilt deshalb: Unser Datenfeld kann die Route nicht selbst auswerten.
- **Was ein Datenfeld bei laufender nativer Kursnavigation bekommt:** `Activity.Info.distanceToNextPoint`, `nameOfNextPoint`, `distanceToDestination`, `offCourseDistance`, `bearingFromStart` (plus `bearing`/`track`/`currentHeading`). Damit ließe sich die Garmin-Navigation nur ergänzen. Kompassring, Abkürzungserkennung (`skipAhead()`) und Rundtour-Startwahl brauchen die echten Punkte.
- **Rohes GPX auf der Uhr:**
  - Mit `HTTP_RESPONSE_CONTENT_TYPE_TEXT_PLAIN` kann der Hintergrundprozess eine GPX-Datei von beliebiger URL als Text laden.
  - Der ganze String muss aber zusammen mit dem Parsen in 64 KB passen. Das reicht allenfalls für kleine geplante Routen, nicht für GPS-Aufzeichnungen mit Tausenden Punkten.
  - XML-Parsen in Monkey C ist teuer; eine Bibliothek dafür gibt es nicht.
- **Handy → Uhr mit Daten:**
  - `Background.registerForPhoneAppMessageEvent()` + `ServiceDelegate.onPhoneAppMessage()` funktionieren im Datenfeld-Hintergrund, auf der fenix 7X mit dem Weckruf-Spike bewiesen (done.md "Routen an Garmin Smartwatches schneller übertragen").
  - `PhoneAppMessage.data` kann Nutzdaten tragen, nicht nur `"sync"`. Eine Handy-App könnte GPX also selbst parsen, vereinfachen und die fertige Nutzlast direkt an die Uhr schicken, ganz ohne Server.
  - Das Connect IQ Mobile SDK gibt es für Android (in YTAN schon genutzt: `GarminWatchPlugin.java`) und für iOS.

**Vorläufige Antworten:**
1. *GPX 1.1 als Austauschformat YTAN ↔ Garmin-App?*
   - Als Import-/Export-Format von YTAN gut geeignet (Routen als `<rte>`/`<trk>` mit Name und Punkten).
   - **Nicht** als Format auf der Uhr: Speicher und Parse-Aufwand sprechen dagegen.
   - Empfehlung: GPX nur an den Rändern (YTAN-Import/-Export, Eingang einer Handy-App). Auf der Uhr bleibt die kompakte Nutzlast, in die einmal umgewandelt wird, auf dem Server oder auf dem Handy. Bei einer Nutzlast für alle Wege bleibt `YtanNavField.mc` unverändert.
2. *Wie kommen GPX-Daten ohne YTAN an die Uhr?* Bewertete Wege:
   - a) **GPX-URL in den App-Einstellungen** (Connect-IQ-Settings, in Garmin Connect editierbar), Uhr lädt als Text. Nur Bordmittel, aber hart größenbegrenzt; die Datei muss öffentlich erreichbar sein.
   - b) **GPX als nativer Kurs über Garmin Connect**, Datenfeld nur auf `Activity.Info`. Nur Bordmittel, aber stark reduzierte Funktion, nicht mehr "unsere" Navigation.
   - c) **Eigene Handy-App** (Android, später iOS): GPX öffnen oder teilen, umwandeln, per Mobile SDK senden. Volle Funktion ohne Server; Aufwand ist eine eigene App, Android-Teil aus `GarminWatchPlugin` wiederverwendbar. **Favorit.**
   - d) **Generischer, zustandsloser Umwandlungsdienst** (GPX-URL rein, Nutzlast raus). Ohne Konto, formal aber wieder ein Backend; nur zur Abgrenzung.

**Was für eine universelle Version am Datenfeld zu tun wäre:**
- Die Routenquelle austauschbar machen. Heute ist sie fest `fetchRoute()` mit `X-Watch-Token` in `YtanSyncService.mc`; dazu käme ein zweiter Eingang über `onPhoneAppMessage` mit Daten bzw. eine Einstellungs-URL.
- Kein eingebauter Key bzw. keine eingebaute Server-URL mehr (`Config.mc`, `bin\build-watch.bat`).
- Veröffentlichung im Connect IQ Store statt Sideload.

**Noch offen / vor einer Entscheidung zu klären:**
- Größengrenze einer Nachricht Handy → Uhr (Mobile SDK `sendMessage`, Weg über `Background.exit()` ins Datenfeld). Am besten in einem Spike messen.
- Maximale Länge eines String-Settings in den App-Einstellungen (Weg a).
- Hintergrund-Speichergrenze der fenix 6 Pro und grob, wie viele GPX-Punkte als Text plus Parsen in 64 KB passen.
- Anforderungen des Connect IQ Store an ein universelles Datenfeld.
- Nächster Schritt, falls gewünscht: schlanker Spike für Weg c (Handy-App schickt eine aus GPX umgewandelte Route als Nachricht ans Datenfeld), analog zum Weckruf-Spike.
