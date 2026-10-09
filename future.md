# Zukünftige Ideen

Punkte, die bewusst zurückgestellt sind - weder offen (todo.md) noch erledigt (done.md). Ein Punkt wandert zurück nach todo.md, sobald er angegangen wird.

## iOS-App neben der Android-App

Was ist nötig, um neben der Android-App auch eine App für iOS zu bauen, und was ist der günstigste Weg dafür?

### Erkenntnisse (2026-10-09)
Ausgangslage: Capacitor 8 (`package.json`, `capacitor.config.json`), die App lädt die Live-Seite `https://ytan.pesr.org/` in einer WebView. Die npm-Plugins `@capacitor-community/background-geolocation`, `@capacitor/filesystem` und `@capacitor/share` gibt es auch für iOS. Eigene Java-Plugins in `android/app/src/main/java/org/pesr/ytan/`: `AppInfoPlugin`, `AppUpdatePlugin` (APK-Selbstupdate), `GarminWatchPlugin` (Connect IQ Mobile SDK), `LocationPermissionsPlugin`, dazu `OfflineAwareWebViewClient`/`NetworkUtils` (Offline-Seite). Ein `ios/`-Projekt gibt es noch nicht.

**Unvermeidbar:**
1. **Apple Developer Program, 99 USD/Jahr.** Ohne läuft eine App nur 7 Tage auf dem eigenen Gerät (per Xcode); TestFlight, App Store und Ad-hoc-Verteilung setzen die Mitgliedschaft voraus.
2. **Ein Mac mit Xcode zum Bauen** - unter Windows nicht möglich. Ein eigener Mac ist aber nicht nötig:
   - **Günstigster Weg:** Codemagic. Persönliches Konto mit 500 kostenlosen macOS-Build-Minuten pro Monat (Stand 2026-10; ein Capacitor-Build dauert grob 5-10 Minuten). Zertifikate und Profile automatisch über den App-Store-Connect-API-Schlüssel, ohne Mac.
   - Alternativen: GitHub Actions mit macOS-Runnern (bei privaten Repos zählen macOS-Minuten zehnfach), ein stundenweise gemieteter Mac, oder ein gebrauchter Mac mini M1 (etwa 300-400 € einmalig) - mit eigenem Mac sind Entwicklung und Debugging im Simulator deutlich bequemer.
3. **Verteilung - kein "APK zum Herunterladen":** iOS kennt kein Sideloading wie Android.
   - **TestFlight** (für den Start empfohlen): bis 10.000 externe Tester per Link, kein öffentlicher Store-Eintrag; nur der erste Build durchläuft eine leichte Beta-Prüfung; jeder Build läuft nach 90 Tagen ab (also regelmäßig neu hochladen).
   - **Ad-hoc:** bis 100 registrierte Geräte pro Jahr.
   - **App Store:** öffentlich, volle Prüfung. Risiko Richtlinie 4.2 ("Minimum Functionality") - reine Webseiten-Apps werden oft abgelehnt; Hintergrund-GPS-Aufzeichnung und Garmin-Anbindung sind aber echte native Funktionen, die dagegen sprechen.

**Kosten günstigster Weg:** 99 USD/Jahr (Codemagic-Free-Tier und TestFlight kostenlos, keine Hardware) plus einmalig der Umbau unten.

**Umbau, falls angegangen (grob 2-4 Tage plus Apple-Formalitäten):**
1. **Projekt:** `npm i @capacitor/ios`, `npx cap add ios`, `cap:sync` auch für `ios`; `ios`-Block in `capacitor.config.json` (gleiche `server.url`, `errorPath`); `Info.plist`: `NSLocationWhenInUseUsageDescription`, `NSLocationAlwaysAndWhenInUseUsageDescription`, `UIBackgroundModes: location`, ggf. Foto-/Teilen-Texte, für Garmin `LSApplicationQueriesSchemes: gcm-ciq` und ein eigener URL-Typ.
2. **Eigene Plugins in Swift nachbauen:**
   - `AppInfoPlugin`: trivial (Bundle-Version).
   - `LocationPermissionsPlugin`: `CLLocationManager`-Status inkl. "Immer".
   - `AppUpdatePlugin`: **entfällt** - Updates kommen über TestFlight/App Store; `native-app.js` verweist dann statt Banner/Download dorthin (`Capacitor.getPlatform()`).
   - `GarminWatchPlugin`: das iOS Connect IQ Mobile SDK arbeitet anders - App und Garmin Connect Mobile tauschen sich über URL-Schemes aus, die Uhr muss einmal über Garmin Connect ausgewählt werden; der aufwendigste Teil. Kann vorerst fehlen - `wakeGarminWatch()` (`capacitor-bridge.js`) verkraftet ein fehlendes Plugin, dann gilt der 5-Minuten-Abruf.
   - Offline-Seite: Capacitor iOS kennt `errorPath`; das Android-Sonderverhalten beim Kaltstart ohne Netz (`OfflineAwareWebViewClient`) prüfen.
3. **JS:** `capacitor-bridge.js`/`native-app.js` auf Android-Annahmen durchsehen (APK-Update, "App installieren"-Zeile per `getInstalledRelatedApps()`).
4. **Build/Signierung:** Codemagic-Workflow (`codemagic.yaml`) mit App-Store-Connect-API-Key, automatische Signierung, Upload nach TestFlight; App-ID `org.pesr.ytan` im Apple-Konto anlegen.
5. **Doku:** CLAUDE.md "Releasing an app version" um iOS ergänzen.

**Prüfen auf einem echten iPhone (TestFlight):** Start lädt die Seite, offline erscheint die Fehlerseite, Standortfreigabe "Immer", Track-Aufzeichnung läuft bei gesperrtem Bildschirm weiter, PDF/GPX lässt sich über das Teilen-Menü speichern, ohne Garmin-Plugin kein Fehler.

Quellen: [Codemagic Pricing](https://docs.codemagic.io/billing/pricing/), [Codemagic: 500 free build minutes](https://help.codemagic.io/articles/4921846342-why-can-t-i-use-the-500-free-build-minutes-on-my-account), [TestFlight overview](https://developer.apple.com/help/app-store-connect/test-a-beta-version/testflight-overview/), [Invite external testers](https://developer.apple.com/help/app-store-connect/test-a-beta-version/invite-external-testers/), [Apple Developer Program](https://developer.apple.com/programs/), [Connect IQ Mobile SDK for iOS](https://developer.garmin.com/connect-iq/core-topics/mobile-sdk-for-ios)

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
- **Stand 2026-10-09:** Der Versand der fertigen Nutzlast von der YTAN-Android-App an die Uhr (Weg c) ist für den eigenen Server bereits gebaut (`GarminWatchPlugin.send()`, done.md "Garmin-Datenfeld ohne Key über die Android-App nutzen"): kein Key im Build, ein `.prg` pro Modell. Für die universelle Version fehlt nur noch, die Nutzlast statt vom YTAN-Server aus einer GPX-Datei auf dem Handy zu erzeugen.
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
