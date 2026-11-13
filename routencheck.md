# Routencheck (KI-Tagesbriefing für Routen)

Name (User 2026-11-13): DE "Routencheck", EN "Route check"; später Touren: "Tourcheck". Kein "Fahrbar?" (klingt nach Zusage).

## Context
Erste KI-Funktion in YTAN (Entscheidung User 2026-11-13): "Kann ich die Route an Tag X fahren?".
Server sammelt Fakten entlang der Route (Wind/Böen/Wellen/Tide, Fahrtrichtung vs. Wind, Ausweich-Anlandestellen,
Gefahrenstellen), Claude formuliert daraus eine Einschätzung mit Zeitfenstern. Zugang über neues Recht `ai_use`
(Kosten pro Aufruf). Weitere Ideen (Hilfe-Chat, Routenvorschlag, Admin-Helfer) kommen nach `future.md`.

Grundsatz: **Zahlen rechnet PHP, das LLM nur Synthese + Sprache.** Pflichthinweis: keine Sicherheitsgarantie.

## Vor Implementierung
- `claude-api`-Skill laden (Modell-ID, Messages-API, Prompt-Caching, Fehlerbehandlung) – nicht aus dem Gedächtnis.
- Modell: `claude-sonnet-5-5`; System-Prompt + Regeln prompt-gecacht.

## Backend

### Daten sammeln – `src/Service/Ai/RouteBriefingContext.php` (neu)
- Route: `RouteRepository::findById()` (+ Sichtbarkeit wie `RouteController::show()`: public/own/admin, sonst 404).
- Punkte aus `points` JSON; Länge selbst per Haversine berechnen (`length` ist Client-Wert).
- Abtastpunkte: Start, Ende, alle ~5 km, max 6 → je `WeatherService::getForecast()` (vorhandener DB-Cache 1800 s,
  liefert stündlich `wind_speed/gusts/direction`, `wave_height`, `tide_height`, `sunrise/sunset`).
  Kein PHP-Decoder für ICON-Windblöcke nötig – Open-Meteo reicht für V1.
- Je Abschnitt: Kurs (Bearing), relativer Windwinkel (Gegen-/Seiten-/Rückenwind), Böen-Max im Zeitfenster.
- Offene Querung: Abtastpunkte mit `WaterDistanceService::distances()` → `sea` groß = weit von Küste.
- POIs nahe Route: neue Methode `PoiRepository::findInBox(minLat,maxLat,minLng,maxLng, array $types, ?array $authUser)`
  (bbox-SQL-Muster aus `ShelterImportRepository.php:340`, Sichtbarkeit public/own), dann in PHP Abstand zur Linie ≤ 2 km;
  Typen 1, 2, 10, 11 (Anlanden/Übernachten), 6 (Gefahr), 9 (Umtragen). Max ~30, nächste zuerst.
- Ergebnis: kompaktes JSON (Stundenwerte nur im gewählten Tag 6–22 Uhr), damit Token-Kosten klein bleiben.

### LLM-Client
- `src/Service/Ai/AiClient.php` (Interface `complete(string $system, array $messages, int $maxTokens): AiResult` mit Text + Token-Zahlen).
- `src/Service/Ai/AnthropicAiClient.php` – curl, `ANTHROPIC_API_KEY`/`AI_MODEL` aus `.env`, Timeout 60 s, `AiException`.
  Muster: `OverpassClient`/`CurlOverpassClient`.
- `tests/Fixtures/FakeAiClient.php` (Muster `FakeOverpassClient`: `willAnswer()`, `public array $calls`).

### Briefing – `src/Service/Ai/RouteBriefingService.php`
- `briefing(array $authUser, int $routeId, string $date, ?int $startHour): array{text, generated_at, cached}`.
- Prompt: Rolle erfahrener Seekajak-Guide, Antwort in User-Sprache (`Translator`-Locale), Markdown, feste Gliederung
  (Fazit / beste Zeitfenster / Abschnitte / Ausweichstellen / Hinweise), nur gelieferte Daten verwenden, keine Erfindung.
- **Cache nur für den KI-Text** (Ampel/Zeitfenster/Kartenfärbung rechnet PHP jedes Mal frisch, kostet nichts):
  - Schlüssel = `sha256(` exakt der an die KI gesendete Kontext-JSON + Locale + Modell + `PROMPT_VERSION` `)`.
    Damit stecken Route, Tag, Startzeit, Richtung, Tempo, Erfahrungsstufe, Regeln und sichtbare POIs automatisch drin –
    gleiche Eingabe = gleiche Antwort, auch über Benutzer hinweg; eigene private POIs ergeben automatisch einen eigenen Eintrag.
  - Wetterwerte im Kontext gerundet (Wind/Böen 0,5 m/s, Welle 0,1 m, Richtung 10°): ein Wetter-Refresh ohne echte
    Änderung trifft weiter den Cache.
  - Gültigkeit `ai_cache_hours` (Admin-Einstellung auf `/admin/ai`, Default 3 h, 0 = aus); danach neu, auch bei gleichem Kontext.
  - Ablage `storage/ai-cache/{key}.json` (`text`, `created_at`, Tokens); Aufräumen täglich über `FileCacheCleanupService`
    in `bin/cleanup-file-caches.php` (älter als 24 h). Keine Personendaten in der Datei.
  - Treffer: zählt nicht ins Tageslimit, wird in `ai_usage` mit `cache_hit=1`, 0 Tokens protokolliert;
    Sheet zeigt "Einschätzung von 10:15 Uhr".
- Log: `LogWriter` Name `ai` (User, Route, Tokens in/out, Dauer, Cache-Hit) – Name in Migration 068-Liste/CLAUDE.md-Lognamen aufnehmen.

### Rechte, Limits, Schalter
- Migration `070_ai_briefing.sql`: `user.ai_use TINYINT(1) UNSIGNED NOT NULL DEFAULT 0`,
  `app_settings.ai_enabled TINYINT(1) NOT NULL DEFAULT 0`, `app_settings.ai_daily_limit INT NOT NULL DEFAULT 20`.
- Recht end-to-end wie `export_routes_own` (Liste aus `done.md:700`): `UserRepository::RIGHT_COLUMNS` + SQL,
  `UserController::RIGHT_FIELDS`, `AuthService::claimsFor()`, `admin-user.js` (`OTHER_RIGHT_FIELDS`, Labels, Default, Payload),
  Frontend-User-Objekt (`helper.js:591`, `user.js:414`, `templates/app.php:734`, `admin-auth.js`), i18n, e2e-Seed, `docs/API.md`.
- Limit: gezählt aus `ai_usage` (Zeilen des Users heute mit `status='ok'`, ohne Cache-Treffer) gegen `ai_daily_limit`;
  kein `RequestThrottle` nötig. Admin zählt auch.
- Schalter: `SettingsRepository::aiSettings()/setAiSettings()`, `PUT /settings/ai` (Admin);
  `window.YTAN_AI_ENABLED` in `templates/app.php`. UI: Admin-Seite `/admin/ai` (siehe unten).

### Endpoint
- `POST /routes/{id}/briefing` body `{date: "YYYY-MM-DD" (heute..+6), start_hour?: 0-23}` →
  `src/Http/Controllers/AiBriefingController.php` (`$c->set()` in `App.php`, sonst `AppWiringTest` rot).
- Reihenfolge: `requireAuthUser` → Schalter an (sonst 403 `ai.disabled`) → `hasRight(ai_use)` (403) → Sichtbarkeit (404)
  → Validierung (422) → Cache → Throttle (429) → LLM (502 `ai.failed` bei `AiException`).
- Admin-Dashboard: kein neuer Health-Check nötig (kein Job).

## Frontend – Bedienkonzept "ein Tipp, Karte bleibt sichtbar"

### Einstieg (wo der Nutzer ohnehin ist)
- **Route-InfoWindow** (`route.js` `showRouteInfoWindow()`): Button "Routencheck" neben Edit/Tour-Icons – Haupteinstieg,
  auf dem Handy auffindbar (Long-Press-Kontextmenü ist es nicht).
- Zusätzlich Eintrag im Route-Kontextmenü (`showRouteContextMenu()` :1151) und Play-Nachbar im `#tourModeBadge` (später Tour).
- Nur sichtbar wenn `window.YTAN_AI_ENABLED && hasRight('ai_use')` – sonst nichts, kein ausgegrautes Lockmittel.
- Icon `auto_awesome`: Ligatur im vendored Font prüfen, sonst inline SVG (Muster `#editToolbar`).

### Ablauf ohne Eingabe
- Tipp → sofort Anfrage mit Defaults: Tag = heute (vor 10 Uhr) sonst morgen, Startzeit = nächste volle Stunde bzw. 9 Uhr,
  Tempo = Profil-Standardgeschwindigkeit (gibt es schon, `Profile > Default speed`), Richtung = wie gezeichnet.
- Kein Prompt-Textfeld. Anpassung danach über Chips im Ergebnis: Tag (Heute/Morgen/+2…+6), Startzeit (−/+ 1 h),
  "Richtung umkehren" (Wind macht Hin/Rück entscheidend). Jede Änderung = neue Anfrage (Cache macht Rücksprünge gratis).

### Ergebnis als Bottom-Sheet (nicht Full-Screen)
- Neues `#briefingSheet` unten (mobil: Peek-Höhe ~35 %, hochwischen = Details; Desktop: rechte Seitenleiste ~380 px).
  Karte bleibt sichtbar und zeigt das Ergebnis:
  - Route abschnittsweise **Ampel-gefärbt** (Polyline-Overlay grün/gelb/rot nach Wind-/Böen-Wertung), Original-Route gedimmt.
  - Ausweich-Anlandestellen/Shelter der Antwort als hervorgehobene Marker; Tipp auf Abschnitt im Text → Karte zoomt hin.
- Sheet-Aufbau von oben:
  1. **Ampel + ein Satz** ("Gut fahrbar bis 13 Uhr, danach Böen 9 m/s von vorn") – Ampel und Abschnittsfarben
     **rechnet PHP deterministisch** (Schwellen), erscheint sofort; KI-Text folgt (Spinner bzw. Streaming).
  2. **Zeitfenster-Leiste** des Tages (Stil der Wetter-Zeitleiste, `weather.js`), gute Stunden markiert, Startzeit-Marker.
  3. Chips Tag / Startzeit / Richtung.
  4. KI-Text in aufklappbaren Abschnitten (Abschnitte, Ausweichstellen, Hinweise) via `renderMarkdown()`.
  5. Fußzeile klein: "KI-Einschätzung, keine Sicherheitsgarantie · Daten: Open-Meteo · noch 17 heute".
- Schließen: x / runterwischen / Escape; entfernt Ampel-Overlay. Kein `panelOpened()` (Karte bleibt bedienbar),
  aber z-index unter Drawer/Panels wie `#tourModeBadge`.
- Einmaliger Hinweis beim ersten Aufruf: Daten (Route + Wetter, keine Personendaten) gehen an Anthropic – bestätigen, merken in Settings-Cookie.

### Fehler / Grenzen
- Limit erreicht: Ampel + Zahlen trotzdem zeigen (kommen ohne KI), nur Text fehlt mit Hinweis "Tageslimit erreicht".
- KI-Fehler/Timeout: dito, plus "Erneut versuchen". So bleibt die Funktion nie leer.

### Dateien
- `public/js/route-briefing.js` (neu, nach `route.js` in `templates/app.php`, `assetVersion`), CSS-Block in `style.css`
  (Tokens, Dark Mode), Markup `#briefingSheet` in `templates/app.php`, `data-on-click` (CSP).
- i18n DE/EN (`resources/i18n/*.json`), Fehlercodes `ai.*`.

### Backend-Folge des UI-Konzepts
- Endpoint teilt sich: Antwort enthält `rating` (Ampel gesamt), `segments[]` (Punktbereich, Farbe, Wind rel., Böen),
  `windows[]` (Stunde → gut/mittel/schlecht), `pois[]` (id, Rolle) **und** `text` (null bei Limit/Fehler).
  Body zusätzlich `reverse: bool`, `speed_kmh`. Schwellen als Konstanten in `RouteBriefingContext`, testbar.

## KI-Anbindung (Konfiguration)
- **Geheimnis nur in `.env`**: `ANTHROPIC_API_KEY` (je Umgebung eigener Key, wie `JWT_SECRET`/`MAPS_API_KEY`; nie DB –
  landet sonst in Dumps/`/admin`-Ausgaben), optional `AI_MODEL` (Default `claude-sonnet-5-5`), `AI_TIMEOUT` (60).
- **Betrieb in DB** (`app_settings`), bedient auf `/admin/ai` (Karte "Status").
- Austauschbar über Interface `AiClient`; `.env` `AI_PROVIDER=anthropic` (einzige Implementierung V1).
- Kostendeckel zusätzlich im Anthropic-Console-Workspace (Spend Limit) – Hinweis in Doku.

## Entscheidungsmodell Ampel (deterministisch, PHP, ohne KI)
Klasse `src/Service/Ai/BriefingRating.php` + Regeln als Daten `BriefingRules` (keine if-Kette).

**Zeitachse**: Abschnitt i wird zur Uhrzeit `start + ETA(i)` befahren; ETA aus Tempo, korrigiert um
Gegenwindanteil (−`headwind_slowdown` km/h je m/s Gegenwindkomponente, Rückenwind kein Bonus).

**Stufen**: 0 grün / 1 gelb / 2 rot, dazu Status grau = keine Bewertung (Daten fehlen, z. B. Stunde außerhalb
der Vorhersage, kein Wetterwert). Grau zählt in der Aggregation nicht als grün: Route mit grauem Abschnitt
zeigt ihre Farbe mit Hinweis "teilweise ohne Daten".

**Kriterien je Abschnitt + Stunde** → Stufe:
| Kriterium | Quelle | Gelb ab | Rot ab |
|---|---|---|---|
| Mittelwind | `wind_speed` | 5 m/s | 8 m/s |
| Böen | `wind_gusts` | 8 m/s | 11 m/s |
| Welle | `wave_height` | 0,5 m | 1,0 m |
| Gewitter | `weather_code` 95–99 | – | immer |
| Dunkelheit | sunrise/sunset | ≤30 min davor/danach | Nacht |
| Wassertemperatur | `sea_surface_temperature` | < 10 °C | – (nur Hinweis/gelb) |

**Modifikatoren**:
- *Ablandiger Wind*: Punkt 2 km in Windrichtung weiter weg von der Küste als der Punkt selbst
  (zwei `distances()`-Aufrufe) → Stufe mindestens gelb, ab Gelbschwelle rot (Abdriftgefahr).
- *Seitenwind + Welle*: Windwinkel 60–120° zur Fahrtrichtung und Welle ≥ Gelb → +1 Stufe.

**Windabdeckung durch Inseln/Küste (Fetch = freie Wasserstrecke gegen den Wind)**:
- Neu `WaterDistanceService::fetch(lat, lng, bearingDeg, maxKm = 30): ?int` (m): Strahl gegen die Windrichtung,
  Zellen des Index entlang des Strahls abgehen (`WaterIndex::segments()` für `sea` + `lake`, 0,05°-Zellen,
  Küstenlinien inkl. Inselringe, auf 5 m vereinfacht), erster Schnitt Strahl–Segment = Fetch. Keine externe Anfrage.
- Je Abtastpunkt 3 Strahlen (Windrichtung ±15°), Median; Ergebnis je 10°-Richtungsklasse gecacht
  (max. 36 Strahlbündel je Punkt, egal wie viele Stunden).
- Wirkung:
  - *Welle*: Open-Meteo-Welle (~5 km Raster, in Förden/Schären oft null oder zu hoch) ersetzt/gedeckelt durch
    fetch-begrenzte Schätzung (SMB/JONSWAP: Hs aus Wind + Fetch); genommen wird das Minimum beider, fehlt Open-Meteo, die Schätzung.
  - *Lee*: Fetch < `lee_fetch_m` (Default 500 m) → Wind-/Böenschwellen × `lee_factor` (1,2), Grund "Lee von Land".
  - *Exponiert* wird fetch-basiert statt Küstenabstand: Fetch > `exposed_fetch_km` (Default 5 km) → Schwellen × 0,8.
- Grenzen (in UI-Fußnote/KI-Prompt): keine Geländehöhe (flache Insel schützt weniger als Steilküste),
  Düseneffekte zwischen Inseln und Fallböen nicht erfasst, Flüsse ohne Ufer im Index (nur Mittellinie) → dort kein Fetch.
- Tests: `WaterDistanceServiceTest::fetch` mit der Test-Fixture `tests/testdata/water-index-sample.bin`
  (Strahl zu bekanntem Ufer, Strahl ins Leere = null), `BriefingRatingTest` Lee/Exponiert/Wellenschätzung.

**Aggregation**: Abschnitt = Maximum über seine befahrene(n) Stunde(n); Route = Maximum der Abschnitte;
`windows[]` = dieselbe Rechnung für jede Startstunde des Tages → "gute Startzeiten". Jede Stufe trägt Grund(e)
(`reasons: [{criterion, value, threshold}]`) – UI zeigt sie, KI erklärt sie, entscheidet aber nichts.

## Anpassbarkeit
- **Admin**: Regelwerk als JSON in `app_settings.briefing_rules` (Migration, NULL = Defaults im Code),
  Karte "Ampel-Regeln" auf `/admin/ai`: Tabelle aller Schwellen + Modifikatoren, "Auf Standard zurücksetzen".
  `BriefingRules::normalize()` validiert/klemmt Werte (Muster `WindConfig::normalizeRegions()`), `PUT /settings/briefing-rules`.
- **Erfahrungsstufen**: Admin pflegt Faktoren für Profile Einsteiger / Erfahren / Experte (Default 0,75 / 1,0 / 1,25,
  multiplizieren Wind-/Böen-/Wellenschwellen); User wählt seine Stufe in Profil (`user.paddle_level`, Default Erfahren)
  und kann sie im Sheet per Chip wechseln.
- Entscheidung User 2026-11-13: User wählt NUR die Erfahrungsstufe; keine eigenen Schwellen pro User.
- Regeln-Hash geht in den Cache-Key (geänderte Regeln = neue Einschätzung).
- Tests: `BriefingRatingTest` pinnt Defaults, Modifikatoren, Aggregation, Startfenster.

## Admin-Seite `/admin/ai` (Sidebar-Eintrag "KI")
Muster wie `/admin/radar`/`/admin/wind`: `templates/admin-ai.php`, `public/js/admin-ai.js`, Route in `App.php`
neben den anderen Admin-Seiten, Controller `AiAdminController` (`requireAdmin`).

### Karte "Status"
- Schalter **KI systemweit an/aus** (`app_settings.ai_enabled`, `PUT /settings/ai`). Aus = Button im Route-InfoWindow
  verschwindet überall, Endpoint 403 `ai.disabled`. Entscheidung User 2026-11-13: der Schalter schaltet die komplette
"Routencheck"-Funktion (Ampel + KI-Text), keine Ampel ohne KI.
- Tageslimit je User, Modell (Anzeige aus `.env`), "API-Key gesetzt: ja/nein" (nie der Key),
  Button "Verbindung testen" (`POST /settings/ai/test`, Mini-Request, Ergebnis + Tokens als Toast).

### Karte "Verbrauch"
- Kennzahlen für gewählten Monat und gesamt: Anzahl Abfragen (ohne Cache-Treffer), **Ø Tokens je Abfrage**
  (Eingabe / Ausgabe / Summe getrennt), Cache-Trefferquote, Fehleranzahl.
- Monatsauswahl (`<select>` der Monate mit Daten, Default aktueller Monat).
- **Tabelle je berechtigtem Nutzer**: alle User mit `ai_use = 1` oder `is_admin = 1`, plus jeder mit Verbrauch im Monat
  (Recht inzwischen entzogen/gelöscht → Name aus Snapshot, markiert). Spalten: Benutzer, Abfragen, Tokens Eingabe,
  Tokens Ausgabe, Tokens gesamt, Ø je Abfrage, letzte Abfrage. User ohne Abfrage mit 0.
- **Sortierbar** per Klick auf Spaltenkopf (Benutzer, Abfragen, Tokens gesamt; Pfeil-Indikator, zweiter Klick dreht);
  clientseitig (eine Zeile je User, kleine Datenmenge), Default Tokens absteigend. Mobil: Tabelle horizontal
  scrollbar im eigenen Container (kein Seiten-Scroll), Spalte Benutzer sticky.

### Guthaben – ZURÜCKGESTELLT (User 2026-11-13: "machen wir später"), nicht Teil von V1
(keine Balance-API bei Anthropic, Stand 2026-11-13)
- Admin trägt Aufladungen von Hand ein (Betrag USD + Datum, `ai_credit_topup`-Zeilen); geschätztes Restguthaben =
  Summe Aufladungen − Kosten seit erster Aufladung, Kosten aus `ai_usage`-Tokens × Preistabelle
  (`AiPricing`, Preise je Modell als Konstanten/Admin-editierbar, inkl. Cache-Lesen).
- Kein Admin-API-Key auf dem Server (darf die ganze Organisation verwalten). Abgleich mit der Console von Hand.
- Warnung im Admin-Dashboard (`AdminHealthService`): geschätztes Guthaben < Schwelle (Default 5 USD) → `ai.credit_low`;
  letzte API-Antwort "Guthaben erschöpft" (Fehlerstatus in `ai_usage`) → `ai.credit_empty`.

### Karte "Ampel-Regeln" (siehe Anpassbarkeit)

### Daten: Tabelle `ai_usage` (Migration 070)
`id, user_id NULL (FK user ON DELETE SET NULL), username_snapshot, route_id NULL, created_at (UTC), model,
input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, cache_hit TINYINT, status ENUM('ok','error','limit'),
duration_ms`, Index `(created_at)`, `(user_id, created_at)`.
- `RouteBriefingService` schreibt jede Anfrage (auch Cache-Treffer mit 0 Tokens, Fehler mit Status) über `AiUsageRepository::record()`.
- `AiUsageRepository::monthlyByUser(string $month)` (GROUP BY, LEFT JOIN berechtigte User), `summary(?string $month)`,
  `months()`, `countToday(int $userId)` (für Limit).
- Endpoint `GET /admin/ai/usage?month=YYYY-MM` → `{summary, months[], users[]}`.
- Tokens aus der API-Antwort (`usage`-Feld), nicht geschätzt.

## Doku / Tracking
- `todo.md`-Eintrag, `future.md`: Hilfe-Chat, Routenvorschlag, Admin-Helfer, Tour-Briefing, ICON-Wind in PHP.
- Datenschutzerklärung (`templates/datenschutz.php`): Abschnitt Anthropic. `.env.example`: `ANTHROPIC_API_KEY`, `AI_MODEL`.
- CLAUDE.md: kurzer Absatz "AI route briefing".

## Verification
- PHPUnit (`composer test`, vorher `composer test-db` nach Migration): `RouteBriefingContextTest` (Bearing/Windwinkel,
  Abtastung, POI-Abstand zur Linie, mit `FakeWeatherForecastRepository`), `PoiRepositoryTest::findInBox` (Sichtbarkeit),
  `AiBriefingControllerTest` (Schalter aus 403, ohne Recht 403, fremde private Route 404, falsches Datum 422,
  Limit 429, Cache-Hit ruft FakeAiClient nicht, AiException 502), `SettingsControllerTest`, `UserRepository`-Rechte,
  `AiUsageRepositoryTest` (Monatsgruppierung, berechtigte User mit 0, gelöschter User per Snapshot, Ø ohne Cache-Treffer,
  `countToday`), `AiAdminControllerTest` (nur Admin, Monatsformat 422).
- Admin-Seite mobil (390×~650): Tabelle scrollt im Container, Sortierung je Spalte.
- e2e: `seed/reset-e2e-db.php` vorher laufen lassen (Migration); Kontextmenü-Spec bleibt grün.
- Manuell: mobil 390×~650 zuerst, dann Desktop; echter Key, Kiel-Förde-Route, Kosten/Tokens im `ai`-Log prüfen.
