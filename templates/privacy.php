<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | Datenschutz</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
</head>

<body>
    <h1>Datenschutzerklärung</h1>

    <h2>§ 1 Allgemeine Informationen</h2>
    <p>Diese Datenschutzerklärung beschreibt, welche personenbezogenen Daten verarbeitet werden, wenn Sie die Website ytan.pesr.org, die dazugehörige Android-App oder das YTAN-Datenfeld für Garmin-Uhren (zusammen „YTAN“) nutzen. Personenbezogene Daten sind alle Daten, mit denen Sie persönlich identifiziert werden können. Wir verarbeiten Ihre Daten nach den gesetzlichen Vorgaben, insbesondere der Datenschutz-Grundverordnung („DSGVO“).</p>
    <p>YTAN ist ein nicht kommerzielles Angebot zur Planung von Kajaktouren. Es gibt keine Werbung, keine Reichweitenmessung, keine Analyse Ihres Nutzungsverhaltens und keine Zahlungsfunktionen.</p>

    <h2>§ 2 Verantwortliche Stelle</h2>
    <p>Die Verantwortung für die Erhebung und Verarbeitung personenbezogener Daten liegt datenschutzrechtlich bei:</p>
    <p>Christoph Steindorff<br>
        Ohnhorster Weg 14, 38527 Meine<br>
        Deutschland<br>
        <a href="mailto:info@pesr.org">info@pesr.org</a>
    </p>

    <h2>§ 3 Hosting, Zugriffsdaten und Protokolle</h2>
    <p>YTAN wird auf Servern eines Hosting-Dienstleisters betrieben, der die Daten in unserem Auftrag verarbeitet. Wenn Sie YTAN aufrufen, übermittelt Ihr Browser oder die App automatisch Zugriffsdaten, die in Server-Logfiles gespeichert werden:</p>
    <ul>
        <li>Browsertyp und Browserversion</li>
        <li>verwendetes Betriebssystem</li>
        <li>Referrer-URL (die Seite, von der aus Sie zu uns gelangt sind)</li>
        <li>Datum und Uhrzeit der Anfrage sowie die aufgerufene Adresse</li>
        <li>die IP-Adresse Ihres Geräts</li>
    </ul>
    <p>Zusätzlich führt YTAN eigene Protokolle:</p>
    <ul>
        <li><strong>Schutz vor Missbrauch:</strong> Anmeldeversuche, Anfragen zum Zurücksetzen eines Passworts sowie Abrufe von Wetterdaten und Ortsnamen werden mit IP-Adresse und Zeitpunkt gezählt, um automatisierte Angriffe und Überlastung zu begrenzen. Diese Einträge werden nach 24 Stunden gelöscht.</li>
        <li><strong>Fehlerprotokolle:</strong> Technische Fehler werden mit Zeitpunkt und Fehlermeldung protokolliert. Meldet Ihr Browser einen Verstoß gegen die Sicherheitsrichtlinie der Seite (Content Security Policy), wird die betroffene Adresse protokolliert.</li>
        <li><strong>Protokolle einzelner Funktionen:</strong> Bei Verwaltungsfunktionen und beim Routencheck (§ 10) wird der Benutzername zusammen mit Zeitpunkt und Vorgang protokolliert.</li>
    </ul>
    <p>Die Verarbeitung erfolgt gemäß Art. 6 Abs. 1 lit. f DSGVO. Unser berechtigtes Interesse liegt im sicheren und stabilen Betrieb von YTAN und in der Aufklärung von Störungen.</p>

    <h2>§ 4 Cookies und lokaler Speicher</h2>
    <p>YTAN setzt nur technisch notwendige Cookies und Einträge im lokalen Speicher Ihres Browsers. Es gibt keine Cookies zur Werbung oder zur Analyse Ihres Nutzungsverhaltens.</p>
    <ul>
        <li><strong>Cookie „gdpr_accepted“</strong> (12 Monate): merkt sich, dass Sie der Nutzung von Google Maps zugestimmt haben (§ 5).</li>
        <li><strong>Cookie „ytan_settings“</strong> (12 Monate): speichert Ihre Einstellungen, zum Beispiel Kartentyp, Einheiten, Sprache, helles oder dunkles Design und die eingeblendeten Kartenebenen.</li>
        <li><strong>Lokaler Speicher „ytan_token“</strong>: hält Sie nach der Anmeldung angemeldet. Der Eintrag wird beim Abmelden gelöscht.</li>
        <li><strong>Weitere Einträge im lokalen Speicher und im Sitzungsspeicher</strong>: Ihre Bestätigung des Hinweises zum Routencheck (§ 10), der Zeitpunkt Ihrer letzten Nutzung (für den Startbildschirm), die Angaben zu Ihrem Benutzerkonto während der Sitzung und die Parameter eines geöffneten geteilten Links.</li>
    </ul>
    <p>Damit YTAN auch bei schlechter Verbindung schnell startet, speichert Ihr Browser Programmdateien und Bilder der Website zwischen (Service Worker). Personenbezogene Daten werden dabei nicht erhoben.</p>
    <p>Die Speicherung erfolgt gemäß § 25 Abs. 2 TDDDG und Art. 6 Abs. 1 lit. f DSGVO, weil sie für die von Ihnen gewünschten Funktionen erforderlich ist. Sie können Cookies und den lokalen Speicher jederzeit in Ihrem Browser löschen. Ihre Zustimmung zu Google Maps können Sie außerdem in YTAN selbst über den Menüpunkt „Cookies“ zurücknehmen.</p>

    <h2>§ 5 Google Maps</h2>
    <p>YTAN verwendet den Kartendienst Google Maps der Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland. Über Google Maps werden die Karte, die Ortssuche und die dafür nötigen Schriften geladen.</p>
    <p>Google Maps ist zunächst deaktiviert. Die Verbindung zu den Servern von Google wird erst aufgebaut, wenn Sie beim ersten Aufruf ausdrücklich zustimmen (Einwilligung nach Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG). Ohne diese Zustimmung kann YTAN nicht genutzt werden, da die Karte der Kern der Anwendung ist.</p>
    <p>Nach der Aktivierung erhält Google Ihre IP-Adresse, Angaben zu Ihrem Browser, den betrachteten Kartenausschnitt und Ihre Eingaben in der Ortssuche. Diese Daten können auch an Server von Google in den USA übertragen werden. Auf diese Verarbeitung haben wir keinen Einfluss.</p>
    <p>Sie können Ihre Einwilligung jederzeit mit Wirkung für die Zukunft widerrufen, indem Sie in YTAN den Menüpunkt „Cookies“ aufrufen und die Zustimmung zurücknehmen.</p>
    <p>Erstellen Sie zu einer Tour ein Dokument (PDF), ruft unser Server die Kartenbilder dafür bei Google ab. Dabei werden der Kartenausschnitt und der Verlauf der Routen übertragen, nicht aber Ihre IP-Adresse oder Angaben zu Ihrer Person.</p>
    <p>Weitere Informationen finden Sie in der Datenschutzerklärung von Google: <a href="https://policies.google.com/privacy?hl=de" target="_blank" rel="nofollow noopener">https://policies.google.com/privacy?hl=de</a>.</p>

    <h2>§ 6 Nutzerkonto</h2>
    <p>Die Karte und alle öffentlichen Inhalte können Sie ohne Nutzerkonto ansehen. Ein Nutzerkonto ist nötig, um eigene Inhalte anzulegen. Nutzerkonten werden nicht selbst registriert, sondern auf Einladung durch einen Administrator angelegt.</p>
    <p>Zu einem Nutzerkonto speichern wir:</p>
    <ul>
        <li>Benutzername, Vorname, Nachname und E-Mail-Adresse</li>
        <li>das Passwort, ausschließlich als nicht umkehrbarer Hashwert</li>
        <li>die Ihnen eingeräumten Berechtigungen</li>
        <li>Ihre Einstellungen, zum Beispiel Standardgeschwindigkeit und Erfahrungsstufe</li>
        <li>bei Einladung, Passwort-Zurücksetzung, Änderung der E-Mail-Adresse und Löschung des Kontos: einen zeitlich begrenzt gültigen Bestätigungscode, als Hashwert</li>
    </ul>
    <p>Vorname, Nachname und E-Mail-Adresse sind nur für Sie selbst und für Administratoren sichtbar. Ihr Benutzername wird anderen Nutzern angezeigt, wenn Sie Inhalte veröffentlichen (§ 7).</p>
    <p>An Ihre E-Mail-Adresse senden wir ausschließlich Nachrichten, die für die Nutzung nötig sind: die Einladung, Links zum Setzen oder Zurücksetzen des Passworts, die Bestätigung einer geänderten E-Mail-Adresse, die Bestätigung und Mitteilung einer Kontolöschung sowie Hinweise, wenn eine Route geändert, gelöscht oder nicht mehr öffentlich ist, die Teil einer Ihrer Touren ist. Wir versenden keinen Newsletter und keine Werbung.</p>
    <p>Die Verarbeitung erfolgt gemäß Art. 6 Abs. 1 lit. b DSGVO, um Ihnen die Nutzung von YTAN mit einem Nutzerkonto zu ermöglichen.</p>
    <p><strong>Löschung des Nutzerkontos:</strong> Sie können Ihr Nutzerkonto jederzeit selbst löschen (Profil &gt; Konto löschen). Dazu geben Sie Ihr Passwort ein und bestätigen die Löschung über einen Link, den wir Ihnen per E-Mail senden; er ist 60 Minuten gültig. Bis Sie den Link geöffnet haben, können Sie den Antrag widerrufen. Bei der Löschung gilt:</p>
    <ul>
        <li>Ihre Kontodaten, Einstellungen, Bestätigungscodes und der Schlüssel für die Garmin-Uhr werden gelöscht.</li>
        <li>Ihre privaten Orte, Routen, Gebiete und Touren werden mit ihren Fotos gelöscht.</li>
        <li>Ihre öffentlichen Inhalte bleiben für alle sichtbar. Sie werden einem Administratorkonto von YTAN zugeordnet und sind danach nicht mehr mit Ihrem Benutzernamen verbunden. Bei aufgezeichneten Routen werden Zeitpunkt und Dauer der Aufzeichnung entfernt. Möchten Sie, dass auch öffentliche Inhalte verschwinden, löschen Sie diese vorher selbst oder stellen Sie sie auf privat.</li>
        <li>Die Aufzeichnung Ihrer Routenchecks (§ 10) bleibt für die Kostenkontrolle mit dem Benutzernamen zum Zeitpunkt der Anfrage erhalten.</li>
    </ul>
    <p>Die Löschung kann nicht rückgängig gemacht werden. Ein Administrator kann Ihr Nutzerkonto nach denselben Regeln löschen, auch auf Ihren Wunsch per E-Mail.</p>

    <h2>§ 7 Von Ihnen erstellte Inhalte</h2>
    <p>Mit einem Nutzerkonto können Sie Orte (POIs), Routen, Gebiete und Touren anlegen, beschreiben, mit Fotos versehen und GPX-Dateien importieren. Diese Inhalte werden zusammen mit der Zuordnung zu Ihrem Nutzerkonto gespeichert.</p>
    <ul>
        <li><strong>Privat oder öffentlich:</strong> Sie legen für jeden Inhalt fest, ob er privat oder öffentlich ist. Private Inhalte sind nur für Sie sowie für Administratoren und Nutzer mit Verwaltungsrechten sichtbar. Veröffentlichen Sie einen Inhalt, kann ihn jeder Besucher sehen, auch ohne Nutzerkonto. Bei öffentlichen Touren wird Ihr Benutzername als Ersteller angezeigt.</li>
        <li><strong>Aufgezeichnete Routen:</strong> Zeichnen Sie eine Route per GPS auf oder importieren Sie eine GPX-Datei mit Zeitangaben, werden Zeitpunkt und Dauer der Aufzeichnung sowie Ihr Benutzername gespeichert. Diese Angaben sehen nur Nutzer, denen ein Administrator die entsprechende Berechtigung erteilt hat.</li>
        <li><strong>Fotos:</strong> Beim Hochladen entfernen wir die in der Datei enthaltenen Zusatzangaben (Metadaten wie EXIF, XMP und IPTC, etwa Aufnahmeort, Aufnahmezeit, Kameramodell oder Kommentare). Erhalten bleibt nur die Angabe, wie das Bild gedreht anzuzeigen ist. Fotos sind für jeden abrufbar, der den zugehörigen Inhalt sehen darf. Laden Sie keine Fotos hoch, auf denen andere Personen ohne deren Einverständnis erkennbar sind.</li>
        <li><strong>Texte:</strong> Bitte tragen Sie in Namen und Beschreibungen keine personenbezogenen Daten Dritter ein.</li>
    </ul>
    <p>Die Verarbeitung erfolgt gemäß Art. 6 Abs. 1 lit. b DSGVO. Sie können Ihre Inhalte jederzeit selbst ändern, wieder auf privat stellen oder löschen. Gelöschte Inhalte und ihre Fotos werden von unserem Server entfernt.</p>

    <h2>§ 8 Standortdaten</h2>
    <p>YTAN greift nur auf den Standort Ihres Geräts zu, wenn Sie das veranlassen und Ihr Browser oder Ihr Gerät es erlaubt, etwa um Ihre Position auf der Karte zu zeigen oder eine Route aufzuzeichnen. Um die Karte an Ihrer Position zu zeigen, werden passende Kartenausschnitte bei Google geladen (§ 5).</p>
    <p>Während einer Aufzeichnung bleibt der zurückgelegte Weg auf Ihrem Gerät. Erst wenn Sie die Aufzeichnung als Route speichern, wird sie an unseren Server übertragen und Ihrem Nutzerkonto zugeordnet (§ 7). Ein laufender Standortverlauf wird auf dem Server nicht geführt.</p>

    <h2>§ 9 Wetter, Ortsnamen und weitere Datenquellen</h2>
    <p>Rufen Sie für eine Stelle der Karte Wetterdaten oder einen Ortsnamen ab, fragt unser Server diese Daten bei externen Diensten an: Open-Meteo (Wetter, Wellen, Gezeiten), dem schwedischen Wetterdienst SMHI und Nominatim der OpenStreetMap Foundation (Ortsnamen). Übertragen werden nur die Koordinaten der gewählten Stelle. Ihre IP-Adresse und Angaben zu Ihrer Person erhalten diese Dienste nicht.</p>
    <p>Regenradar, Windkarte, Leuchtfeuer, Rastplätze und weitere Kartendaten bezieht unser Server unabhängig von einzelnen Nutzern aus öffentlichen Quellen. Dabei werden keine Daten von Nutzern übertragen. Die Quellen sind unter dem Menüpunkt „Über“ aufgeführt.</p>
    <p>Einige Stellen in YTAN verlinken auf fremde Websites, zum Beispiel auf das Regenradar von Windy. Erst wenn Sie einen solchen Link öffnen, erhält der jeweilige Anbieter Daten von Ihnen. Für dessen Datenverarbeitung ist er selbst verantwortlich.</p>

    <h2>§ 10 Routencheck (KI-Einschätzung einer Route)</h2>
    <p>Angemeldete Nutzer mit der entsprechenden Berechtigung können eine Route für einen Tag einschätzen lassen (Routencheck). Die Bewertung selbst (Ampel) errechnet unser Server. Den erläuternden Text erzeugt ein KI-Dienst der Anthropic, PBC, San Francisco, USA (Sprachmodell „Claude“).</p>
    <p>An Anthropic übertragen wird je Anfrage:</p>
    <ul>
        <li>Datum, Start- und Endzeit, Länge der Route, angenommene Geschwindigkeit, gewählte Erfahrungsstufe und Fahrtrichtung</li>
        <li>je Abschnitt der Route: Kilometerbereich, Uhrzeit, Kurs als Himmelsrichtung, Wind, Böen, Wellenhöhe, freie Wasserstrecke und die errechnete Bewertung mit ihren Gründen</li>
        <li>Zeiten von Sonnenauf- und -untergang und die angewendeten Schwellenwerte</li>
        <li>Name, Art und Lage (Kilometer entlang der Route, Abstand) von Orten in der Nähe der Route. Dazu gehören öffentliche Orte und Ihre eigenen, auch privaten Orte.</li>
    </ul>
    <p>Nicht übertragen werden Ihr Name, Ihr Benutzername, Ihre E-Mail-Adresse, Ihre IP-Adresse, der Name und die Beschreibung der Route sowie Koordinaten. Die Anfrage stellt unser Server, nicht Ihr Gerät. Namen Ihrer privaten Orte werden jedoch mitgesendet. Geben Sie Orten deshalb keine Namen, die Rückschlüsse auf Personen zulassen, wenn Sie den Routencheck nutzen.</p>
    <p>Anthropic verarbeitet die Daten auf Servern in den USA. Weitere Informationen: <a href="https://www.anthropic.com/legal/privacy" target="_blank" rel="nofollow noopener">https://www.anthropic.com/legal/privacy</a>.</p>
    <p>Auf unserem Server speichern wir zu jeder Anfrage Zeitpunkt, Benutzerkonto, Route und die Zahl der verbrauchten Recheneinheiten (Tokens), um die Kosten zu kontrollieren und ein Tageslimit je Nutzer einzuhalten. Der erzeugte Text wird ohne Bezug zu Ihrer Person für höchstens 24 Stunden zwischengespeichert und kann in dieser Zeit für eine inhaltlich gleiche Anfrage wiederverwendet werden. Ihre Erfahrungsstufe speichern wir in Ihrem Nutzerkonto.</p>
    <p>Vor der ersten Nutzung weisen wir Sie auf die Übertragung hin. Die Verarbeitung erfolgt gemäß Art. 6 Abs. 1 lit. b DSGVO, um Ihnen die von Ihnen angeforderte Funktion bereitzustellen, die Verbrauchsaufzeichnung gemäß Art. 6 Abs. 1 lit. f DSGVO. Der Routencheck ist eine Planungshilfe. Er trifft keine Entscheidung mit rechtlicher Wirkung im Sinne von Art. 22 DSGVO.</p>

    <h2>§ 11 Android-App und Garmin-Uhr</h2>
    <p><strong>Android-App:</strong> Die App zeigt YTAN in einer eigenen Oberfläche. Für sie gilt diese Datenschutzerklärung entsprechend. Die App verwendet folgende Berechtigungen:</p>
    <ul>
        <li>Standort, auch im Hintergrund: nur während Sie eine Route aufzeichnen, damit die Aufzeichnung bei ausgeschaltetem Bildschirm weiterläuft (§ 8)</li>
        <li>Benachrichtigungen: für die Anzeige der laufenden Aufzeichnung</li>
        <li>Installieren von Apps: um eine neue Version der App zu installieren, die Sie von unserem Server laden</li>
    </ul>
    <p>Die App wird nicht über einen App-Store verteilt, sondern von unserem Server geladen. Beim Start fragt sie dort die aktuelle Versionsnummer ab.</p>
    <p><strong>Garmin-Uhr:</strong> Wenn Sie das YTAN-Datenfeld nutzen, speichern wir zu Ihrem Nutzerkonto einen Schlüssel für die Uhr, die gewählte Route und Ihre Anzeigeeinstellungen. Die Uhr ruft die Route mit diesem Schlüssel von unserem Server ab. Senden Sie die Route aus der Android-App, läuft die Übertragung über die App Garmin Connect auf Ihrem Telefon. Für die Verarbeitung durch Garmin gilt deren Datenschutzerklärung.</p>

    <h2>§ 12 Kontaktaufnahme</h2>
    <p>Wenn Sie uns per E-Mail kontaktieren, speichern wir Ihre Nachricht und Ihre Kontaktdaten, um Ihre Anfrage zu bearbeiten und Rückfragen zu beantworten. Die Verarbeitung erfolgt gemäß Art. 6 Abs. 1 lit. f DSGVO, bei Anfragen zu Ihrem Nutzerkonto gemäß Art. 6 Abs. 1 lit. b DSGVO. Wir löschen die Daten, sobald Ihre Anfrage erledigt ist und keine gesetzlichen Aufbewahrungspflichten entgegenstehen.</p>

    <h2>§ 13 Empfänger und Übermittlung in Drittländer</h2>
    <p>Wir verkaufen Ihre Daten nicht und nutzen sie nicht für Werbung. Personenbezogene Daten erhalten nur:</p>
    <ul>
        <li>unser Hosting-Dienstleister, auf dessen Servern YTAN betrieben wird (§ 3)</li>
        <li>unser E-Mail-Dienstleister, über den die in § 6 genannten Nachrichten versendet werden</li>
        <li>Google, wenn Sie Google Maps aktiviert haben (§ 5)</li>
        <li>Anthropic, wenn Sie den Routencheck nutzen, im dort beschriebenen Umfang (§ 10)</li>
    </ul>
    <p>Google und Anthropic verarbeiten Daten auch in den USA. Die Übermittlung stützt sich auf den Angemessenheitsbeschluss der Europäischen Kommission für die USA (EU-U.S. Data Privacy Framework), soweit der Anbieter danach zertifiziert ist, andernfalls auf Standardvertragsklauseln nach Art. 46 Abs. 2 lit. c DSGVO.</p>
    <p>Darüber hinaus geben wir Daten nur weiter, wenn wir gesetzlich dazu verpflichtet sind oder Sie ausdrücklich eingewilligt haben.</p>

    <h2>§ 14 Verschlüsselung (SSL/TLS)</h2>
    <p>YTAN verwendet eine SSL- bzw. TLS-Verschlüsselung. Eine verschlüsselte Verbindung erkennen Sie am „https://“ in der Adresszeile Ihres Browsers und am Schloss-Symbol. Die Daten, die Sie an uns senden, können so nicht von Dritten mitgelesen werden.</p>

    <h2>§ 15 Speicherdauer</h2>
    <p>Wir speichern personenbezogene Daten nur so lange, wie es für den jeweiligen Zweck nötig ist:</p>
    <ul>
        <li>Nutzerkonto und Ihre Inhalte: bis Sie sie löschen oder das Nutzerkonto gelöscht wird. Öffentliche Inhalte bleiben danach ohne Bezug zu Ihrer Person erhalten (§ 6).</li>
        <li>Zähler zum Schutz vor Missbrauch (IP-Adresse): 24 Stunden</li>
        <li>Bestätigungscodes aus E-Mails: bis zu ihrem Ablauf oder ihrer Verwendung</li>
        <li>Verbrauchsaufzeichnung des Routenchecks: bis zur Löschung des Nutzerkontos, danach nur noch mit dem Benutzernamen zum Zeitpunkt der Anfrage</li>
        <li>Zwischengespeicherte Texte des Routenchecks: höchstens 24 Stunden</li>
        <li>Protokolldateien von YTAN: ohne feste Frist. Ältere Einträge werden archiviert und von uns gelöscht, sobald sie zur Aufklärung von Störungen nicht mehr gebraucht werden.</li>
        <li>Server-Logfiles des Hosting-Dienstleisters: nach dessen Vorgaben</li>
        <li>Cookies: 12 Monate, sofern Sie sie nicht vorher löschen</li>
    </ul>

    <h2>§ 16 Ihre Datenschutzrechte</h2>
    <p>Als betroffene Person der Datenverarbeitung haben Sie, in Übereinstimmung mit den gesetzlichen Bestimmungen, die folgenden Rechte gegenüber dem Verantwortlichen bezüglich Ihrer personenbezogenen Daten:</p>

    <h3>A. Widerrufsrecht</h3>
    <p>Viele Datenverarbeitungen sind nur mit Ihrer ausdrücklichen Zustimmung möglich. Wenn die Verarbeitung Ihrer Daten auf Ihrer Einwilligung basiert, haben Sie das Recht, diese Einwilligung gemäß Art. 7 Abs. 3 DSGVO jederzeit mit Wirkung für die Zukunft zu widerrufen. Die Rechtmäßigkeit der Datenverarbeitung, die bis zum Widerruf auf der Grundlage Ihrer Einwilligung erfolgt ist, bleibt davon unberührt.</p>

    <h3>B. Auskunftsrecht</h3>
    <p>Gemäß Art. 15 DSGVO haben Sie das Recht, von uns eine Bestätigung darüber zu verlangen, ob wir Ihre personenbezogenen Daten verarbeiten. Ist dies der Fall, so haben Sie Anspruch auf Auskunft über diese Daten, einschließlich der Verarbeitungszwecke, der Kategorien der verarbeiteten Daten, der Empfänger oder Kategorien von Empfängern, gegenüber denen die Daten offengelegt wurden oder werden, die geplante Speicherdauer bzw. die Kriterien für deren Festlegung, das Bestehen eines Rechts auf Berichtigung, Löschung, Einschränkung der Verarbeitung, Widerspruch gegen die Verarbeitung, Beschwerde bei einer Aufsichtsbehörde, die Herkunft der Daten, sofern diese nicht bei Ihnen erhoben wurden, das Bestehen einer automatisierten Entscheidungsfindung einschließlich Profiling und ggf. aussagekräftige Informationen über die involvierte Logik sowie die Sie betreffende Tragweite und die angestrebten Auswirkungen einer solchen Verarbeitung, sowie Ihr Recht auf Unterrichtung, welche Garantien gemäß Art. 46 DSGVO bei Weiterleitung Ihrer Daten in Drittländer bestehen.</p>

    <h3>C. Recht auf Berichtigung</h3>
    <p>Sie haben das Recht, jederzeit gemäß Art. 16 DSGVO die Korrektur unrichtiger personenbezogener Daten, die Sie betreffen, und/oder die Ergänzung Ihrer unvollständigen Daten von uns zu verlangen.</p>

    <h3>D. Recht auf Löschung</h3>
    <p>Sie haben das Recht, gemäß Art. 17 DSGVO die Löschung Ihrer personenbezogenen Daten zu verlangen, wenn einer der folgenden Gründe vorliegt:</p>
    <ol type="a">
        <li>Ihre personenbezogenen Daten werden nicht mehr für die Zwecke benötigt, für die sie erhoben oder anderweitig verarbeitet wurden.</li>
        <li>Sie widerrufen Ihre Zustimmung, die die Verarbeitung gemäß Art. 6 Abs. 1 lit. a oder Art. 9 Abs. 2 lit. a DSGVO legitimierte, und es besteht keine andere Rechtsgrundlage für die Verarbeitung.</li>
        <li>Sie widersprechen der Verarbeitung gemäß Art. 21 Abs. 1 DSGVO und es gibt keine zwingenden schutzwürdigen Gründe für die Verarbeitung, oder Sie widersprechen der Verarbeitung gemäß Art. 21 Abs. 2 DSGVO.</li>
        <li>Es liegt eine unrechtmäßige Verarbeitung der personenbezogenen Daten vor.</li>
        <li>Wir sind aufgrund einer rechtlichen Verpflichtung nach dem Unionsrecht oder dem Recht des Mitgliedstaates, dem wir unterliegen, zur Löschung der personenbezogenen Daten verpflichtet.</li>
        <li>Die Erhebung der personenbezogenen Daten erfolgte im Zusammenhang mit der Bereitstellung von Diensten der Informationsgesellschaft gemäß Art. 8 Abs. 1 DSGVO.</li>
    </ol>
    <p>Unter folgenden Umständen kann dieses Recht eingeschränkt sein, wenn die Verarbeitung unerlässlich ist:</p>
    <ol type="a">
        <li>um einer rechtlichen Verpflichtung nachzukommen, die die Verarbeitung nach dem Recht der Union oder des Mitgliedstaates, dem wir unterliegen, erfordert, oder um eine Aufgabe im öffentlichen Interesse oder in Ausübung öffentlicher Gewalt wahrzunehmen;</li>
        <li>zur Erfüllung einer rechtlichen Verpflichtung, die die Verarbeitung nach dem Recht der Union oder des Mitgliedstaates, dem wir unterliegen, erfordert, oder zur Wahrnehmung einer Aufgabe, die im öffentlichen Interesse liegt oder in Ausübung öffentlicher Gewalt erfolgt, die uns übertragen wurde;</li>
        <li>aus Gründen des öffentlichen Interesses im Bereich der öffentlichen Gesundheit gemäß Art. 9 Abs. 2 lit. h und i sowie Art. 9 Abs. 3 DSGVO;</li>
        <li>für Archivzwecke, wissenschaftliche oder historische Forschungszwecke oder für statistische Zwecke gemäß Art. 89 Abs. 1 DSGVO, die im öffentlichen Interesse liegen, sofern die Wahrung der Rechte der betroffenen Person die Ziele dieser Verarbeitung unmöglich machen oder ernsthaft beeinträchtigen würde; oder</li>
        <li>zur Geltendmachung, Ausübung oder Verteidigung von Rechtsansprüchen.</li>
    </ol>
    <p>Sollten wir Ihre personenbezogenen Daten öffentlich gemacht haben und gemäß den vorherigen Bestimmungen zu deren Löschung verpflichtet sein, so ergreifen wir unter Berücksichtigung der verfügbaren Technologie und der Implementierungskosten angemessene Maßnahmen, auch technischer Art, um die für die Datenverarbeitung Verantwortlichen, die diese Daten verarbeiten, darüber in Kenntnis zu setzen, dass Sie als betroffene Person die Löschung aller Links zu Ihren personenbezogenen Daten oder von Kopien oder Replikationen dieser Daten verlangt haben.</p>

    <h3>E. Recht auf Einschränkung der Verarbeitung</h3>
    <p>Gemäß Art. 18 DSGVO haben Sie das Recht, die Einschränkung der Verarbeitung (Sperrung) Ihrer personenbezogenen Daten zu verlangen. Um dieses Recht auszuüben, können Sie sich jederzeit an uns wenden. Die Kontaktdaten finden Sie in § 2. Eine Einschränkung der Verarbeitung kann in folgenden Fällen verlangt werden:</p>
    <ol type="a">
        <li>Wenn Sie die Richtigkeit Ihrer bei uns gespeicherten personenbezogenen Daten bestreiten, benötigen wir in der Regel Zeit, um dies zu überprüfen. Für die Dauer der Prüfung haben Sie das Recht, die Einschränkung der Verarbeitung Ihrer personenbezogenen Daten zu verlangen.</li>
        <li>Wenn die Verarbeitung Ihrer personenbezogenen Daten unrechtmäßig geschah / geschieht, können Sie statt der Löschung die Einschränkung der Datenverarbeitung verlangen.</li>
        <li>Wenn wir Ihre personenbezogenen Daten nicht mehr benötigen, Sie sie jedoch zur Ausübung, Verteidigung oder Geltendmachung von Rechtsansprüchen benötigen, haben Sie das Recht, statt der Löschung die Einschränkung der Verarbeitung Ihrer personenbezogenen Daten zu verlangen.</li>
        <li>Wenn Sie einen Widerspruch nach Art. 21 Abs. 1 DSGVO eingelegt haben, muss eine Abwägung zwischen Ihren und unseren Interessen vorgenommen werden. Solange noch nicht feststeht, wessen Interessen überwiegen, haben Sie das Recht, die Einschränkung der Verarbeitung Ihrer personenbezogenen Daten zu verlangen.</li>
    </ol>
    <p>Nach einer Einschränkung der Verarbeitung Ihrer personenbezogenen Daten dürfen diese grundsätzlich nur noch mit Ihrer Einwilligung verarbeitet werden. Ausnahmen gelten für bestimmte, gesetzlich definierte Fälle, wie beispielsweise zur Geltendmachung von Rechtsansprüchen oder zum Schutz öffentlicher Interessen.</p>

    <h3>F. Recht auf Unterrichtung</h3>
    <p>Sollten Sie von Ihrem Recht auf Berichtigung, Löschung oder Einschränkung der Verarbeitung Ihrer personenbezogenen Daten Gebrauch machen, sind wir gemäß Art. 19 DSGVO verpflichtet, alle Empfänger, denen die Daten offengelegt wurden, darüber zu informieren. Dies gilt nur dann nicht, wenn sich die Mitteilung als unmöglich erweist oder mit einem unverhältnismäßigen Aufwand verbunden ist. Auf Ihren Wunsch hin teilen wir Ihnen die Empfänger Ihrer Daten mit.</p>

    <h3>G. Schutz vor automatisierten Entscheidungen (Profiling)</h3>
    <p>Gemäß Art. 22 DSGVO haben Sie das Recht, nicht einer ausschließlich auf automatisierter Verarbeitung – einschließlich Profiling – beruhenden Entscheidung unterworfen zu werden, die Ihnen gegenüber rechtliche Wirkung entfaltet oder Sie in ähnlicher Weise erheblich beeinträchtigt.</p>
    <p>Dies gilt nicht, wenn die Entscheidung</p>
    <ol type="a">
        <li>für den Abschluss oder die Erfüllung eines Vertrags zwischen Ihnen und uns notwendig ist,</li>
        <li>gemäß Rechtsvorschriften der Union oder der Mitgliedstaaten, denen der Verantwortliche unterliegt, zulässig ist und diese Vorschriften angemessene Maßnahmen zur Wahrung Ihrer Rechte, Freiheiten und berechtigten Interessen enthalten oder</li>
        <li>mit Ihrer ausdrücklichen Zustimmung erfolgt ist.</li>
    </ol>
    <p>Die Entscheidungen in den unter (a) bis (c) genannten Fällen dürfen jedoch nicht auf besonderen Kategorien personenbezogener Daten im Sinne von Art. 9 Abs. 1 DSGVO beruhen, es sei denn, Art. 9 Abs. 2 lit. a oder lit. g findet Anwendung und es wurden geeignete Maßnahmen zum Schutz der Rechte und Freiheiten sowie Ihrer berechtigten Interessen getroffen.</p>
    <p>In den unter (a) und (c) genannten Fällen ergreifen wir angemessene Maßnahmen, um Ihre Rechte und Freiheiten sowie Ihre berechtigten Interessen zu schützen. Dazu gehört mindestens das Recht, ein menschliches Eingreifen durch den Verantwortlichen zu verlangen, Ihren eigenen Standpunkt darzulegen und die Entscheidung anzufechten.</p>

    <h3>H. Recht auf Datenübertragbarkeit</h3>
    <p>Im Falle einer Verarbeitung Ihrer personenbezogenen Daten, die auf Ihrer Einwilligung gemäß Art. 6 Abs. 1 lit. a DSGVO oder Art. 9 Abs. 2 lit. a DSGVO oder auf einem Vertrag gemäß Art. 6 Abs. 1 lit. b DSGVO beruht und mithilfe automatisierter Verfahren erfolgt, haben Sie nach Art. 20 DSGVO das Recht, Ihre uns bereitgestellten Daten in einem strukturierten, gängigen und maschinenlesbaren Format zu erhalten und diese an einen anderen Verantwortlichen zu übermitteln oder die Übermittlung durch uns an einen anderen Verantwortlichen zu verlangen, sofern dies technisch realisierbar ist.</p>

    <h3>I. Widerspruchsrecht</h3>
    <p>Wenn wir Ihre personenbezogenen Daten auf Grundlage einer Interessenabwägung gemäß Art. 6 Abs. 1 lit. f DSGVO verarbeiten, steht Ihnen das Recht zu, aus Gründen, die sich aus Ihrer persönlichen Situation ergeben, jederzeit Widerspruch gegen diese Verarbeitung einzulegen; dies gilt auch für ein darauf basierendes Profiling. Die jeweils geltende Rechtsgrundlage für die Verarbeitung können Sie dieser Datenschutzerklärung entnehmen. Im Falle eines Widerspruchs werden wir Ihre betroffenen personenbezogenen Daten nicht mehr verarbeiten, es sei denn, wir können zwingende schutzwürdige Gründe für die Verarbeitung nachweisen, die Ihre Interessen, Rechte und Freiheiten überwiegen, oder die Verarbeitung ist für die Geltendmachung, Ausübung oder Verteidigung von Rechtsansprüchen erforderlich (Widerspruch gemäß Art. 21 Abs. 1 DSGVO).</p>
    <p>Im Falle der Verarbeitung Ihrer personenbezogenen Daten zum Zwecke der Direktwerbung sind Sie berechtigt, jederzeit Widerspruch gegen diese Verarbeitung einzulegen; dies gilt auch für das Profiling, sofern es mit solcher Direktwerbung in Verbindung steht. Im Falle eines Widerspruchs werden Ihre personenbezogenen Daten nicht mehr für Direktwerbungszwecke verwendet (Widerspruch gemäß Art. 21 Abs. 2 DSGVO).</p>
    <p>In Bezug auf die Nutzung von Diensten der Informationsgesellschaft haben Sie, ungeachtet der Richtlinie 2002/58/EG, die Möglichkeit, Ihr Widerspruchsrecht mittels automatisierter Verfahren, unter Anwendung technischer Spezifikationen, auszuüben.</p>

    <h3>J. Beschwerderecht bei der zuständigen Aufsichtsbehörde gemäß Art. 77 DSGVO</h3>
    <p>Im Falle von Verstößen gegen die Bestimmungen der DSGVO steht den betroffenen Personen ein Recht zur Beschwerde bei einer zuständigen Aufsichtsbehörde zu. Die Beschwerde kann insbesondere in dem Mitgliedstaat, in dem die betroffene Person ihren gewöhnlichen Aufenthalt, ihren Arbeitsplatz hat oder in dem der Ort des mutmaßlichen Verstoßes liegt, eingelegt werden. Das Beschwerderecht nach dieser Bestimmung besteht unbeschadet anderweitiger verwaltungsrechtlicher oder gerichtlicher Rechtsbehelfe.</p>
    <p>Unsere zuständige Aufsichtsbehörde ist:</p>
    <p>Die Landesbeauftragte für den Datenschutz Niedersachsen
        <br>Prinzenstraße 5
        <br>30159 Hannover
        <br>
        <br>Telefon: +49 511 120-4500
        <br>E-Mail: <a href="mailto:poststelle@lfd.niedersachsen.de">poststelle@lfd.niedersachsen.de</a>
        <br>Internet: <a href="https://lfd.niedersachsen.de/" target="_blank" rel="nofollow noopener">https://lfd.niedersachsen.de/</a>
    </p>

    <h2>§ 17 Gültigkeit und Änderung dieser Datenschutzerklärung</h2>
    <p>Diese Datenschutzerklärung tritt am 13.11.2026 in Kraft. Wir behalten uns vor, diese Erklärung bei Bedarf und unter Einhaltung der geltenden Datenschutzgesetze zu ändern. Dies kann beispielsweise erforderlich sein, um neuen rechtlichen Anforderungen gerecht zu werden oder um Änderungen an unserer Website oder neuen, über unsere Website angebotenen Diensten zu berücksichtigen. Die jeweils aktuelle Version der Datenschutzerklärung, die zum Zeitpunkt Ihres Besuchs auf unserer Website abrufbar ist, ist bindend.</p>
    <p>Im Falle von Änderungen an dieser Datenschutzerklärung werden wir diese auf dieser Seite veröffentlichen, um Sie umfassend darüber zu informieren, welche personenbezogenen Daten wir erfassen, wie wir sie verarbeiten und unter welchen Bedingungen wir sie gegebenenfalls weitergeben.</p>
</body>
</html>
