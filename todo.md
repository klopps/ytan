# Offene Punkte

## Touren-Dokument

Ausgabe eines PDF-Dokuments für eine Tour mit 
- Bildern und Beschreibung der Gesamttour
- Bildern und Beschreibungen der einzelnen Routen
Die Bilder der Karte können alternativ als Hybrid, Terrain oder SAT ausgebeben werden.


## Logo

Logo-Alternative entwickeln


## Mobile-Anzeige-Audit (2026-09-10)

Alle Dialoge/Bildschirme unter echter Mobile-Emulation (412×915) durchgetestet.

**Bereits behoben:**
- Forgot-Password-Seite (`/forgot-password`): Karte ragte über den Bildschirmrand (fehlendes `box-sizing: border-box` bei `#forgotPasswordBox` - das Padding wurde zur 95%-Breite addiert statt darin enthalten zu sein). Behoben.
- Set-Password-Seite (`/set-password`): identischer Fehler bei `#setPasswordBox`. Behoben.

**Behoben (2026-09-11):**
- GDPR-Einwilligungsbildschirm: Der Lupe-Button (Kartensuche, oben links) überlappte den Einwilligungstext, obwohl der Nutzer noch gar nicht zugestimmt hatte. Ursache: `.map-search-wrapper` hatte kein `display: none` als Ausgangszustand (anders als `#sidemenu-toggle`, das schon immer erst nach der Zustimmung eingeblendet wird). Jetzt standardmäßig ausgeblendet und wird wie der Hamburger-Button erst in `loadGoogleMaps()` nach Zustimmung sichtbar.
- Derselbe Fehler auch beim GPS-Standort-Button (`#gotomylocation`, unten rechts) gefunden und auf dieselbe Art behoben.
- "privacy policy"-Link im GDPR-Dialog verwies auf `/legal/datenschutz` (keine registrierte Route, 404) statt auf `/legal/privacy` wie überall sonst in der App. Korrigiert.
- Direkt aufgerufene Datenschutz-/Impressum-/About-/Passwort-Seiten (z.B. über den jetzt korrigierten GDPR-Link, der `target="_blank"` nutzt) ließen sich nicht scrollen und hatten keinerlei Rand (Inhalt direkt an der Bildschirmkante): Sie laden dieselbe style.css wie die SPA, deren `html, body { overflow: hidden; margin: 0; padding: 0; }` nur für die SPA gedacht ist (dort hat jeder scrollbare/gepolsterte Bereich seinen eigenen inneren Container). Alle sechs eigenständigen Seiten (about/confirm-email/forgot-password/imprint/privacy/set-password) tragen jetzt eine `standalone-page`-Klasse auf `<html>`, die normales Scrollen und 20px Rand zurückgibt, ohne den SPA-Fix anzutasten.
- Überschriften auf diesen Seiten (z.B. Datenschutzerklärung) wirkten bei Zeilenumbruch zu eng: `line-height: 1.2em` auf `html, body` berechnete sich als fester Pixelwert relativ zur Basis-Schriftgröße (16px) und wurde von h1/h2 unverändert geerbt, statt sich an deren eigene (viel größere) Schriftgröße anzupassen - eine 32px-Überschrift hatte dadurch nur 19.2px Zeilenhöhe, weniger als die Schrift selbst hoch ist. Auf `line-height: 1.2` (ohne Einheit) geändert, wodurch sich der Wert korrekt pro Element neu berechnet. Betrifft die ganze App, nicht nur diese Seiten, aber überall nur reparierend (Elemente in Basis-Schriftgröße bleiben unverändert).

**Geprüft, keine Probleme gefunden:**
- About-/Impressum-/Datenschutz-Seiten
- Drawer: Root, POIs, Site Settings, Preferences (inkl. Profil bearbeiten / Passwort ändern)
- Tours-Panel (Liste, Detail, Formular, Edit Routes) und Users-Panel (Liste, Formular) - in dieser Sitzung bereits mehrfach gefixt und erneut bestätigt
- POI-Bearbeiten-Dialog (Google-InfoWindow)
- Bestätigungsdialog (Löschen), Captcha-Dialog, Toast-Benachrichtigungen
- "Second Toolbar" (Editing-Anzeige), Tour-Mode-Badge

**Nicht abschließend testbar - am echten Gerät gegenprüfen:**
- Route- und Area-Bearbeiten-Dialoge: nutzen dieselbe (als korrekt bestätigte) Markup-Struktur wie der POI-Dialog, konnten aber wegen ungenauer Klick-Positionierung auf der Route-/Area-Linie im Test nicht vollständig geöffnet werden.
- "Add to tour"-Popup aus dem Routen-InfoWindow heraus: aus demselben Grund nicht getestet.
- Contextmenüs (Long-Press auf Route/Area/POI): für POIs auf dem echten Gerät bestätigt (siehe oben). Für Route/Area-Linien wegen ungenauer Klick-Positionierung im Test nicht separat verifiziert - der Hit-Test-Mechanismus ist aber derselbe.


# Erledigt

## Wetter-Zeitleiste

~~Gibt es frei verfügbare Wetterdaten, die wir einbinden könnten?~~ Gelöst (2026-09-13): **Open-Meteo** (open-meteo.com) als Datenquelle - kostenlos, kein API-Key, 10.000 Abrufe/Tag im nicht-kommerziellen Free-Tier, liefert sowohl allgemeines Wetter als auch Seegang/Wellen aus einer Quelle. Backend-Proxy mit Server-seitigem Cache (`src/Service/WeatherService.php`, `storage/weather-cache/`, 30-Minuten-TTL, Koordinaten auf 2 Nachkommastellen gerundet), `GET /api/v1/weather?lat=&lng=`, öffentlich ohne Login.

Ursprünglich als automatisch nachladendes Widget geplant, auf expliziten Wunsch aber auf **rein bedarfsgesteuert** umgestellt: Rechtsklick (Desktop) bzw. Long-Press (Touch) auf einen leeren Kartenpunkt öffnet ein neues, **erweiterbares** Kartenkontextmenü (`map-core.js`: `registerMapContextMenuItem()`/`showMapContextMenu()`) mit dem Eintrag "Meteodaten für diesen Ort". Ausgewählt öffnet sich ein Bottom-Sheet mit einer horizontal scrollbaren Stundenliste über 7 Tage (Temperatur, Wind mit Richtungspfeil, Niederschlag, bei Küstenlage zusätzlich Wellenhöhe). Die Windgeschwindigkeit jeder Karte bekommt einen farbigen Balken exakt nach **Windys eigener Farbskala** (live aus `windy.com`s eigener App ausgelesen, nicht geschätzt). Ein zweiter Menüpunkt "Auf Windy öffnen" verlinkt direkt auf windy.com für denselben Ort. Ein Marker (rote Pin mit weißem Wolken-Symbol als Label) markiert währenddessen den Ort auf der Karte.

Die Einbindung des neuen Kontextmenüs musste in die bestehende (in einer früheren Session aufwändig gefixte) Long-Press-Infrastruktur eingreifen (`findLongPressTarget()`/`fireLongPress()`) - Regressionstest gegen POI-/Route-/Area-Contextmenüs live durchgeführt, keine Regression.

Windgeschwindigkeit ist zusätzlich in **Preferences** als eigene Einheit wählbar (Bft/m/s/km/h/kn, unabhängig von Metrisch/Nautisch), inkl. Beaufort-Verfeinerung mit "-"/"+"-Suffix je nach Position innerhalb der Bft-Stufe (z.B. "4-", "4", "4+"). Ein Layout-Bug (Wetterzeilen wurden auf kurzen Viewports vom Karten-`overflow:hidden` abgeschnitten) wurde gefunden und behoben.

Ergänzt (2026-09-13): der Zeitleisten-Titel zeigt jetzt statt nur der Koordinaten eine echte Ortsbezeichnung, per Reverse-Geocoding über **Nominatim** (OpenStreetMap, kostenlos, `src/Service/GeocodingService.php`, `GET /api/v1/geocode/reverse`, 30-Tage-Cache). Läuft parallel zum Wetter-Abruf, verzögert/blockiert ihn also nicht, und fällt bei Fehlschlag oder fehlendem Ergebnis (z.B. offene See) stillschweigend auf die reinen Koordinaten zurück. Pflicht-Attribution ("© OpenStreetMap-Mitwirkende") wird nur eingeblendet, wenn tatsächlich ein Ortsname angezeigt wird.

## Menü-Reihenfolge

~~Ändere die Reihenfolge der Menüpunkte: POIs, Touren, Teilen, Profil, Einstellungen, Systemeinstellungen.~~ Gelöst (2026-09-13): Reihenfolge im Root-Menü entsprechend angepasst (`templates/app.php`).

## Profile Menü

~~Die Funktionen zum Benutzer, die bislang im Menü "Preferences" unterhalb des horizontalen Linie stehen, müssen in ein neues Menü "Profile". Das Icon und der Status der Benutzeranmeldung  von Preferences gehören dann zum Menüpunkt Profile. Der Menüpunkt Preferences benötigt ein neues Symbol/Icon.~~ Gelöst (2026-09-12): neuer Menüpunkt "Profile" mit dem account_circle-Icon und dem Anmeldestatus als Untertitel (bisher bei Preferences), öffnet einen eigenen Screen mit Login/Konto-Funktionen. "Preferences" zeigt jetzt nur noch Erscheinungsbild/Einheiten/Sprache und hat ein neues Icon (tune).

## Kosmetik

~~In Site Settings > Users  soll wie bei Tours > Edit die Maske von Rechts ins Bild einfliegen und das Menü nach links aus dem Bild schieben und nicht oberhalb der Userliste eingeblendet werden.~~ Gelöst (Tours/Users-Redesign, siehe Commit-Historie). Zusätzlich (2026-09-11) auch Legal-Menü (About/Imprint/Privacy Note) und Cookies-Menü auf dasselbe Muster umgestellt: öffnen über dem weiterhin sichtbaren Drawer statt ihn zu schließen, Zurück-Pfeil mit Titel statt Schließen-Kreuz.

## Aufruf des Kontextmenüs am Smartphone

~~Was kann auf dem Smartphone statt eines Klicks mit der rechten Maustaste genutzt werden, um das contextMenu einer Route, Areas oder eines POIs zu öffnen?~~ Gelöst: Long-Press (~550ms) öffnet jetzt das contextMenu (kurzer Tap öffnet weiterhin nur das normale InfoWindow). Auf dem echten Smartphone bestätigt (2026-09-11) - Erkennung basiert auf einem eigenen Hit-Test der Touch-Koordinaten statt auf Maps' 'mousedown', da dieses Event bei Touch auf dem realen Gerät gar nicht zuverlässig feuert.

## i18n-Tool

~~Es wird ein Tool benötigt, mit dem man komfortabel die Übersetzungen der Texte vornehmen kann. Idealerweise wir der entsprechende Dialog in dem der Text verwendet wird, direkt angezeigt. Gibt es bereits entsprechende Software oder muss etwas gebaut werden?~~ Gelöst (2026-09-12): eigenes, kleines Dev-Tool gebaut (fertige Software wie Crowdin/Locize/Tolgee wäre für 312 Keys/2 Sprachen deutlich überdimensioniert gewesen und hätte das eigene JSON-Format nicht ohne Anpassungsarbeit verstanden). Erreichbar unter `/translate` - nicht im normalen App-Menü verlinkt, nur per `TRANSLATE_TOOL_ENABLED=true` in `.env` aktiviert (Standard: aus), zusätzlich admin-only gegated. Zeigt alle Keys nach Namespace gruppiert und durchsuchbar, EN/DE nebeneinander editierbar, pro Key die Fundstellen (Datei:Zeile, automatisch per Grep ermittelt) sowie eine Live-Vorschau der laufenden App im iframe daneben (ein automatischer Sprung zum exakten Dialog war nicht praktikabel - die App-UI ist stark zustandsabhängig). Speichert direkt in `resources/i18n/{en,de}.json`, mit Warnung bei abweichenden Platzhaltern und Schutz gegen `</script>`-Werte (könnten sonst site-weite Script-Injection ermöglichen, da die Übersetzungen in ein `<script>`-Tag auf jeder Seite eingebettet werden).

## Service-Worker Cache-Bug (2026-09-12)

Nach Umschalten der Sprache (EN→DE) zeigte die About-Seite weiterhin den alten Text - ein normales Reload (F5) half nicht, erst Ctrl+F5 zeigte schließlich Deutsch. Ursache: `sw.php`s Fetch-Handler behandelte bislang JEDEN Same-Origin-Request cache-first, nicht nur die statischen Shell-Assets (JS/CSS/Icons) - eine dynamische, vom Cookie abhängige Seite wie `/about` wurde beim ersten Laden dadurch dauerhaft im Cache Storage eingefroren und nie wieder neu geladen, unabhängig vom Browser-eigenen HTTP-Cache (den ein `fetch(..., {cache:'no-store'})` umgangen hätte - der Service Worker fängt den Request aber schon vorher ab). Behoben: der Fetch-Handler cached jetzt nur noch Requests mit statischen Datei-Endungen (`.js`/`.css`/`.png`/`.svg`/`.webmanifest`/...), alles andere (auch künftige neue Routen) geht immer ins Netz. Zusätzlich räumt die `activate`-Phase bereits bestehende Fehlcache-Einträge automatisch auf, sodass auch schon betroffene Browser sich selbst heilen, ohne dass Nutzer manuell die Website-Daten löschen müssen. Live per Playwright verifiziert (Cache-Inhalt vor/nach dem Fix direkt inspiziert, das gemeldete Szenario in beide Richtungen nachgestellt).
