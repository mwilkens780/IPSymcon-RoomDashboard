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
  - Sonos: nur die Player-Instanz auswählen, der Rest (Play/Pause, aktueller Titel, Lautstärke, Playlist, Gruppe) wird automatisch aus den vorhandenen Sonos-Variablen gelesen.
  - Luftfeuchtigkeit: nur die Luftfeuchtigkeitsrechner-Instanz auswählen -- Lüftungsempfehlung, Ergebnistext und Taupunkte werden automatisch übernommen.
- **Temperatur (Soll/Ist)**: frei erweiterbare Liste, pro Zeile eine Soll- und/oder Ist-Temperatur-Variable (z.B. Thermostat).
- **Status-Variablen**: frei erweiterbare Liste für beliebige Variablen mit Wertprofil-Auswahlliste (z.B. Bewohner-Status im Schlafzimmer) -- Auswahlmöglichkeiten werden automatisch aus dem Profil übernommen, direkt über das Dashboard änderbar.
- **Lichter, Rollläden, Sensoren**: frei erweiterbare Listen (beliebig viele Zeilen, auch keine). Lichter erkennen automatisch, ob die Variable ein Schalter (Ein/Aus) oder ein Dimmer (Schieberegler) ist. Sensoren haben einen Typ (Fenster/Tür/Feuchtigkeit/Rauchmelder/Sirene/Sonstiges), der bestimmt, wie sie dargestellt werden.

## Funktionsweise

Dieses Modul besitzt keine der zugrunde liegenden Variablen. Schreibaktionen (Lichtschalter, Dimmer, Rollladenposition, Sonos-Steuerung, Lüftungsmodus) werden über die globale IPS-Funktion `RequestAction()` an die jeweils konfigurierte Variable weitergereicht -- keine eigene Geräteanbindung nötig.

## Kachel einrichten

Die Kachel in einer Tile-Visualization platzieren. Die Höhe richtet sich danach, wie viele Widgets/Listen-Einträge der jeweilige Raum tatsächlich hat.
