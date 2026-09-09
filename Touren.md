# Touren
YTAN soll um eine Tour-Funktionalität erweitert werden. Dabei setzt sich eine Tour aus einer oder mehreren Routen 
zusammen. Sinn der Tour-Funktionalität ist, dass man nach Auswahl einer Tour nur noch die Routen sieht, die zu der Route gehören und nicht von anderen Routen abgelenkt wird.

## Details

### Informationen zur Tour

Zu einer Tour gehören neben den Routen folgende Informationen:

- Name der Tour (Pflichtfeld), mindestens 3 Buchstaben
- Beschreibung (optional)
- Stichwortliste/Tags (optional)
- Bilder (optional)
- Gesamtlänge der Tour: Berechnet aus der Summe der Routenlängen. Wird die Tour um Routen ergänzt, werden Routen entfernt oder ändert sich die Länge einer Route, muss die Gesamtlänge neu berechnet werden. (automatisch)
- Der Ersteller der Route. (automatisch)
- Wenn eine Route öffentlich ist, wer die Route öffentlich gemacht hat. (automatisch)

### Rechte

- Es gibt die neuen Rechte "Touren erstellen", "Touren veröffentlichen", "Touren kopieren" und "Touren verwalten"
- Einfache Benutzer können keine Touren erstellen.
- Benutzer mit dem Recht "Touren erstellen" können Touren erstellen, bearbeiten und löschen. Diese Touren sind jedoch nur für sie selbst sichtbar.
- Benutzer mit dem Recht "Touren veröffentlichen" können für eigene Touren die Veröffentlichung an- und ausschalten.
- Benutzer mit dem Recht "Touren verwalten" (Tour-Administratoren) dürfen die Touren aller Benutzer veröffentlichen oder die Veröffentlichung zurücknehmen. Sie dürfen die Informationen zu einer Tour verändern, sie dürfen Touren löschen.
- Benutzer mit dem Recht "Touren kopieren" können von Touren kopieren erzeugen. Dabei wird Benutzer zum Ersteller der Kopie der Tour.
- Administratoren können Benutzern die Rechte "Touren erstellen", "Touren veröffentlichen" und "Touren verwalten" geben und entziehen.

### Oberfläche

- Alle Benutzer haben Zugang zu einem Menü in der sie die bestehenden Touren einsehen können.
- Alle Benutzer sehen im Menü eigene und öffentliche Touren und können darin suchen (Name, Beschreibung, Ersteller, Länge der Tour (von-bis), Ersteller)
- Alle Benutzer können Details zu eigenen und öffentlichen Touren anzeigen lassen.
- Alle Benutzer können eine Tour aktivieren, so dass nur noch die Routen dieser Tour angezeigt werden (Tour-Modus).
- Alle Benutzer können den Tour-Modus wieder ausschalten.
- Ist eine Tour aktiviert, wird auf der Oberfläche der Name der Tour angezeigt und mit einem Symbol kenntlich gemacht, dass der sich Benutzer im Tour-Modus befindet.

### Verwaltung

- Benutzer mit den entsprechenden Rechten, können Touren öffentlich machen oder die Veröffentlichung zurücknehmen.
- In der Benutzerverwaltung müssen die neuen Rechte den Benutzern erteilt und wieder genommen werden können. Die Liste der Benutzer soll nach allen Rechten gefiltert werden können.

### Verhalten
- Werden Routen verändert, die zu einer Tour gehören, werden alle Ersteller einer Tour, die diese Route beinhaltet, per E-Mail darüber informiert und gebeten ihre Tour zu kontrollieren.
- Werden Routen gelöscht, die zu einer Tour gehören, werden alle Ersteller einer Tour, die diese Route beinhaltet, per E-Mail darüber informiert und gebeten ihre Tour zu kontrollieren. War die entsprechende Route öffentlich, so wird sie automatisch "nicht öffentlich". Auch darüber wird der Ersteller informiert.
- Versucht ein Benutzer eine Route zu lösche, die Bestandteil mindestens einer Tour ist, wird er vor dem Löschen darüber informiert, dass die Route Bestandteil von x Touren ist und sich gut zu überlegen, ob er die Route tatsächlich löschen will. Will er dennoch löschen, muss der Benutzer ein einfaches Captcha lösen.

## Technische Hinweise

- Es existiert bereits die Datenbanktabellen tour und tour_route. Diese müssen ggfs. werden, z. B. für Speicherung von Tags und Bildern.
- Es existiert bereits ein Menüpunkt Touren. Der muss aber so nicht bestehen bleiben und kann bei Bedarf ganz anders gestaltet werden.
- Folge dem Mobile-First-Ansatz.

## Offene Punkte

- Es ist noch unklar, wie ein Benutzer mehrere Routen zu einer Tour zusammemfassen kann. Evtl. ist ein Tour-Edit-Mode erforderlich. Ich benötige mehrere Vorschläge und Designvorschläge, wie das intuitiv umgesetzt werden kann.
- Die Gestaltung der Tour-Auswahl und Verwaltung muss gut geplant werden. Ich benötige einen Designvorschlag.
