~~In Site Settings > Users  soll wie bei Tours > Edit die Maske von Rechts ins Bild einfliegen und das Menü nach links aus dem Bild schieben und nicht oberhalb der Userliste eingeblendet werden.~~ Gelöst (Tours/Users-Redesign, siehe Commit-Historie). Zusätzlich (2026-09-11) auch Legal-Menü (About/Imprint/Privacy Note) und Cookies-Menü auf dasselbe Muster umgestellt: öffnen über dem weiterhin sichtbaren Drawer statt ihn zu schließen, Zurück-Pfeil mit Titel statt Schließen-Kreuz.

~~Was kann auf dem Smartphone statt eines Klicks mit der rechten Maustaste genutzt werden, um das contextMenu einer Route, Areas oder eines POIs zu öffnen?~~ Gelöst: Long-Press (~550ms) öffnet jetzt das contextMenu (kurzer Tap öffnet weiterhin nur das normale InfoWindow). Auf dem echten Smartphone bestätigt (2026-09-11) - Erkennung basiert auf einem eigenen Hit-Test der Touch-Koordinaten statt auf Maps' 'mousedown', da dieses Event bei Touch auf dem realen Gerät gar nicht zuverlässig feuert.

## i18n-Tool

Es wird ein Tool benötigt, mit dem man komfortabel die Übersetzungen der Texte vornehmen kann. Idealerweise wir der entsprechende Dialog in dem der Text verwendet wird, direkt angezeigt.


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