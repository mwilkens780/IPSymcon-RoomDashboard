# Room Dashboard – IP-Symcon Modul

Generisches Raum-Dashboard im selben dunklen Kachel-Stil wie SunRiser8, BMW CarData, Weather Dashboard, Heating Dashboard und Energy Dashboard. Eine Instanz pro Raum -- jeder Raum bekommt so viele Lichter/Rollläden/Sensoren wie er tatsächlich hat, statt eines starren Schemas.

## Installation

Modulverwaltung → + → URL eintragen:
```
https://github.com/mwilkens780/IPSymcon-RoomDashboard
```

Danach für jeden Raum eine eigene Instanz anlegen und den Instanznamen auf den Raumnamen setzen (z.B. "Schlafzimmer") -- der wird direkt als Kachel-Titel verwendet.

## Konfiguration

- **Präsenz, Sonos, Luftfeuchtigkeit**: feste, aber optionale Felder. Ohne Zuweisung wird das jeweilige Widget im Dashboard einfach nicht angezeigt.
  - Sonos: nur die Player-Instanz auswählen, der Rest (Play/Pause, aktueller Titel, Lautstärke, Playlist, Gruppe) wird automatisch aus den vorhandenen Sonos-Variablen gelesen. Gruppieren fügt den ausgewählten Player als Mitglied *dieses* Raums hinzu (der eigene Titel spielt weiter, statt dass dieser Raum den Inhalt des anderen Players übernimmt); "keine" trennt alle eventuell mit diesem Raum gruppierten Player wieder.
  - Luftfeuchtigkeit: nur die Luftfeuchtigkeitsrechner-Instanz auswählen -- Lüftungsempfehlung, Ergebnistext und Taupunkte werden automatisch übernommen.
- **Temperatur**: frei erweiterbare Liste -- pro Zeile den Thermostat-Knoten (die Geräte-Instanz, z.B. Homematic-Heizkörperthermostat) auswählen. Soll-Temperatur (per Dreh-Regler einstellbar), Ist-Temperatur und Heizmodus (als Buttons, falls das Gerät einen hat) werden automatisch aus den vorhandenen Datenpunkten gelesen. Ist in der Sensoren-Liste ein Feuchtigkeitssensor konfiguriert, wird dessen Wert automatisch mit angezeigt.
- **Status-Variablen**: frei erweiterbare Liste für beliebige Variablen mit Wertprofil-Auswahlliste (z.B. Bewohner-Status im Schlafzimmer) -- die möglichen Zustände werden automatisch aus dem Profil übernommen und als Buttons zum Umschalten dargestellt.
- **Automatisierung**: frei erweiterbare Liste für beliebige weitere schaltbare Variablen (z.B. eine Systemvariable für eine Lichtautomatisierung). Boolean-Variablen ohne Wertprofil werden als Schalter dargestellt, Variablen mit Profil-Auswahlliste als Buttons -- gleiches Prinzip wie bei Status-Variablen, nur als eigener Bereich.
- **Lichter**: frei erweiterbare Liste. Bei Hue- oder Govee-Lampen den obersten Geräteknoten (die Instanz) auswählen statt einer Rohvariable -- das Modul erkennt Hersteller und Typ (Schalter/Dimmer/Farbe) automatisch und bindet die passenden Bedienelemente (Schalter, Helligkeitsregler, Farbwähler) einheitlich in die Kachel ein. Funktioniert genauso mit dem Geräteknoten eines Homematic-Kanals (erkennt automatisch dessen `STATE`/`LEVEL`-Datenpunkte) oder mit der rohen Variable selbst. Ab zwei Lichtern erscheint zusätzlich ein Sammelschalter im Titel des Bereichs, der alle Lichter unabhängig von Typ/Hersteller gemeinsam an- bzw. ausschaltet.
- **Rollläden**: frei erweiterbare Liste. Bei TaHoma-Rollläden den Geräteknoten auswählen -- bidirektionale (mit Positions-Rückmeldung) bekommen Auf/Zu-Schnellwahl plus Regler mit Prozentanzeige, unidirektionale (nur Auf/Stop/Zu, kein Rückkanal, z.B. RTS) automatisch nur die drei passenden Tasten ohne sinnlose Prozentwerte. Bei einer einfachen 0-100-Variable (z.B. Homematic-Aktor) weiterhin die Variable selbst wählen.
- **Rauchmelder**: frei erweiterbare Liste. Bei einer [Nest-Protect](https://github.com/mwilkens780/IPSymcon-NestProtect)-Instanz werden Rauch-/CO-/Hitze-Alarm, Batterie, Stromversorgung, Stummschaltung, letzter Selbsttest und Austauschdatum angezeigt; bei einem Homematic-Rauchmelder-Kanal nur der Alarmstatus (mehr liefert dieser Melder-Typ nicht). Beide über denselben Geräteknoten auswählbar, Hersteller wird automatisch erkannt.
- **Sensoren**: frei erweiterbare Liste mit Typ (Fenster/Tür/Feuchtigkeit/Rauchmelder/Sirene/Sonstiges), der bestimmt, wie der Sensor dargestellt wird.

## Funktionsweise

Dieses Modul besitzt keine der zugrunde liegenden Variablen. Schreibaktionen (Lichtschalter, Dimmer, Rollladenposition, Sonos-Steuerung, Lüftungsmodus) werden über die globale IPS-Funktion `RequestAction()` an die jeweils konfigurierte Variable weitergereicht -- keine eigene Geräteanbindung nötig.

## Kachel einrichten

Die Kachel in einer Tile-Visualization platzieren. Die Höhe richtet sich danach, wie viele Widgets/Listen-Einträge der jeweilige Raum tatsächlich hat.
