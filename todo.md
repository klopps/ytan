In Site Settings > Users  soll wie bei Tours > Edit die Maske von Rechts ins Bild einfliegen und das Menü nach links aus dem Bild schieben und nicht oberhalb der Userliste eingeblendet werden.

~~Was kann auf dem Smartphone statt eines Klicks mit der rechten Maustaste genutzt werden, um das contextMenu einer Route, Areas oder eines POIs zu öffnen?~~ Gelöst: Long-Press (~550ms) öffnet jetzt das contextMenu (kurzer Tap öffnet weiterhin nur das normale InfoWindow). Auf dem echten Smartphone bestätigt (2026-09-11) - Erkennung basiert auf einem eigenen Hit-Test der Touch-Koordinaten statt auf Maps' 'mousedown', da dieses Event bei Touch auf dem realen Gerät gar nicht zuverlässig feuert.

Wenn ich in der Liste der Touren ganz nach unten scrolle und dann eine Tour öffne,  muss ich erst wieder nach oben scrollen, um die Details zu sehen. Das ist unerwartet und ungewollt.

Ausgabe eines PDF-Dokuments für eine Tour mit 
- Bildern und Beschreibung der Gesamttour
- Bildern und Beschreibungen der einzelnen Routen
Die Bilder der Karte können alternativ als Hybrid, Terrain oder SAT ausgebeben werden.

Logo-Alternative entwickeln

## Mobile-Anzeige-Audit (2026-09-10)

Alle Dialoge/Bildschirme unter echter Mobile-Emulation (412×915) durchgetestet.

**Bereits behoben:**
- Forgot-Password-Seite (`/forgot-password`): Karte ragte über den Bildschirmrand (fehlendes `box-sizing: border-box` bei `#forgotPasswordBox` - das Padding wurde zur 95%-Breite addiert statt darin enthalten zu sein). Behoben.
- Set-Password-Seite (`/set-password`): identischer Fehler bei `#setPasswordBox`. Behoben.

**Neu gefunden, noch offen:**
- GDPR-Einwilligungsbildschirm: Der Lupe-Button (Kartensuche, oben links) überlappt den Einwilligungstext, obwohl der Nutzer noch gar nicht zugestimmt hat. Sollte ausgeblendet sein, solange der GDPR-Screen sichtbar ist.

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