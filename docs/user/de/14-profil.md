---
title: Profil
keywords: Anmeldung, Login, Passwort, Passwort vergessen, E-Mail ändern, Standardgeschwindigkeit, Autorouting, Abmelden, Auf allen Geräten abmelden, Konto
---

Menü > **Profil** ist die Zentrale für dein Konto.

## Anmelden

![Die Anmeldung](anmeldung.jpg)

Gib **Benutzername oder E-Mail-Adresse** und dein **Passwort** ein und tippe auf **Anmelden**. Mit dem Augen-Symbol im Passwortfeld blendest du die Eingabe ein oder aus. Nach der Anmeldung bleibst du angemeldet, bis du dich abmeldest oder die Anmeldung nach einiger Zeit (standardmäßig 24 Stunden) abläuft.

Ein Konto bekommst du von den Administratoren: Sie laden dich ein, du erhältst eine E-Mail mit einem Link, über den du dein erstes Passwort festlegst.

## Passwort vergessen

Tippe auf **Passwort vergessen?**, gib Benutzername oder E-Mail-Adresse ein und tippe auf **Link senden**. Falls es ein Konto dazu gibt, bekommst du eine E-Mail mit einem Link, über den du ein neues Passwort festlegst. Der Link funktioniert nur einmal und nur begrenzte Zeit.

## Wenn du angemeldet bist

![Das Profil](profil.jpg)

Oben siehst du deinen Namen und deine E-Mail-Adresse. Darunter:

| Eintrag | Wirkung |
|---|---|
| **Profil bearbeiten** | Vor- und Nachname und E-Mail-Adresse ändern. Bei einer neuen E-Mail-Adresse schickt YTAN eine **Bestätigungsmail** dorthin; bis du den Link darin öffnest, bleibt die alte gültig, und im Profil steht „E-Mail-Änderung wartet auf Bestätigung". Du kannst die Änderung dort wieder zurücknehmen. |
| **Passwort ändern** | Aktuelles Passwort und zweimal das neue eingeben. |
| **Standard-Geschwindigkeit** | Deine übliche Paddelgeschwindigkeit, siehe unten. |
| **Autorouting** | Vorbelegung des Autoroutings: Abstand zum Ufer ein- oder ausschalten, Mindest- und Höchstabstand in Metern. Ein leeres Feld bedeutet den Standard des Betreibers (angezeigt als Platzhalter). Siehe [Anlegen und Bearbeiten](#chapter-anlegen-bearbeiten). |
| **Garmin-Uhr** | Verbindung mit der Garmin-Uhr, siehe [Garmin-Uhr](#chapter-garmin-uhr). |
| **Abmelden** | Beendet die Anmeldung auf diesem Gerät. |
| **Auf allen Geräten abmelden** | Meldet dich nach einer Rückfrage auf allen **anderen** Geräten und Apps ab, zum Beispiel wenn du ein Handy verloren hast. Dieses Gerät bleibt angemeldet. |

Auch wenn du dein Passwort änderst oder über „Passwort vergessen" neu setzt, meldet YTAN dich auf allen anderen Geräten ab.

### Passwortregeln

Ein Passwort muss mindestens **8 Zeichen** lang sein und mindestens **drei der vier Zeichenarten** enthalten: Kleinbuchstaben, Großbuchstaben, Ziffern, Sonderzeichen. Wähle ein Passwort, das du nirgends sonst verwendest.

## Standard-Geschwindigkeit

Trage hier deine **durchschnittliche Geschwindigkeit** ein (in km/h oder Knoten, je nach Einheit). Die Garmin-Uhr rechnet die voraussichtliche Ankunftszeit (**ETA**) anfangs mit diesem Wert, bis sie nach etwa fünf Minuten Paddeln deine tatsächliche Geschwindigkeit gemessen hat. Lässt du das Feld leer oder auf 0, erscheint die ETA erst nach der ersten Messung. Auch der [Routencheck](#chapter-routencheck) rechnet Ankunftszeiten mit diesem Wert (ohne Eintrag mit 6 km/h).

## Etmal

Das **Etmal** ist die Strecke, die du auf Touren im Schnitt pro Tag paddelst - üblich sind 20 bis 30 km. Unter **Profil > Etmal** trägst du deinen Wert ein (ganze Kilometer von 5 bis 60).

Klickst du mit der rechten Maustaste auf eine Wasserfläche (auf dem Smartphone: lange drücken) und wählst **Etmal-Reichweite**, zeichnet YTAN ein, wie weit du von dort **über Wasser** kommst: eine gestrichelte Linie in Orange und Weiß für dein Etmal und dünnere, orange gestrichelte Linien alle 5 km (bei einem Etmal über 30 km alle 10 km). Auf offener See sind das Kreise; Inseln, Landzungen und gewundene Gewässer verformen sie - gezählt wird der kürzeste Weg über Wasser, nicht die Luftlinie. Wind, Strömung und Pausen sind dabei nicht berücksichtigt. Die Linien enden am Ufer. Das Kreuz in der Leiste unten blendet sie wieder aus . Die Reichweite gibt es nur dort, wo YTAN Gewässerdaten hat (Europa), nicht weit draußen auf See.

> **Hinweis:** Die reine Etmal-Reichweite berücksichtigt keine Strömungen oder Windverhältnisse. Diese können die Erreichbarkeit von Orten innerhalb einer "normalen" Zeit für die Entfernung erheblich beeinflussen - dafür gibt es die Berechnung **mit Wind & Strömung**.

### Mit Wind & Strömung

Tippe in der Leiste unten auf **Mit Wind & Strömung …**. Es öffnet sich ein Fenster (auf dem Smartphone unten, am Computer rechts), in dem du **Tag** (heute bis in sechs Tagen) und **Startzeit** wählst. YTAN rechnet dann aus, wie weit du in welcher Zeit kommst:

- Die Linien zeigen die **Paddelzeit**: eine dünne Linie je volle Stunde („1 h“, „2 h“ …) und die kräftige Linie für dein Etmal, beschriftet mit der Ankunftszeit. Die Dauer ist dein Etmal geteilt durch deine [Standardgeschwindigkeit](#profil-standard-geschwindigkeit) (ohne Eintrag 6 km/h) - bei 25 km und 6 km/h also 4:10 Stunden.
- **Wind:** Gegenwind bremst dich, Rückenwind schiebt dich an (je m/s Wind 0,3 km/h, höchstens auf das 1,5-Fache deiner Geschwindigkeit, mindestens auf 30 %). Die Linien rücken daher gegen den Wind näher heran und reichen mit dem Wind weiter. Der Wind kommt aus den Daten der [Windebene](#chapter-wetter), wo es keine gibt, aus der Wettervorhersage. Das Fenster zeigt den mittleren Wind am Startpunkt und die Quelle.
- **Strömung:** Auf Flüssen rechnet YTAN wie im [Routencheck](#chapter-routencheck) mit einer angenommenen Flussströmung, in Flussmündungen mit Gezeiten mit dem Tidenstrom aus Hoch- und Niedrigwasserzeiten. Mit **−** und **+** passt du die Stärke an. Strömungen auf dem Meer und an Küsten kennt YTAN nicht.

Jede Änderung im Fenster berechnet die Linien neu, das dauert einige Sekunden. Das Kreuz im Fenster schließt es, die Linien bleiben; die Leiste zeigt dann Tag, Zeit und Wind, mit **Ändern** öffnest du das Fenster wieder. **Nur Strecke** kehrt zur reinen Etmal-Reichweite zurück. Auch diese Berechnung ist keine Sicherheitsbewertung - ob Wind und Wellen für dich passen, sagt der Routencheck.
