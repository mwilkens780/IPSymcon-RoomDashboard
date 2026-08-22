# Room Dashboard – IP-Symcon Modul

Generisches Raum-Dashboard im selben dunklen Kachel-Stil wie SunRiser8, BMW CarData, Weather Dashboard, Heating Dashboard und Energy Dashboard. Eine Instanz pro Raum -- jeder Raum bekommt so viele Lichter/Rollläden/Sensoren wie er tatsächlich hat, statt eines starren Schemas.

## Installation

Modulverwaltung → + → URL eintragen:
```
https://github.com/mwilkens780/IPSymcon-RoomDashboard
```

Danach für jeden Raum eine eigene Instanz anlegen und den Instanznamen auf den Raumnamen setzen (z.B. "Schlafzimmer") -- der wird direkt als Kachel-Titel verwendet.

## Konfiguration

- **Präsenz, Lüftung, Sonos**: feste, aber optionale Felder. Ohne Zuweisung wird das jeweilige Widget im Dashboard einfach nicht angezeigt.
  - Lüftung: eine beliebige Variable mit Wertprofil (Betriebsart/Stufe) -- die Auswahlmöglichkeiten werden automatisch aus dem Profil übernommen.
  - Sonos: nur die Player-Instanz auswählen, der Rest (Play/Pause, Lautstärke, Playlist, Gruppe) wird automatisch aus den vorhandenen Sonos-Variablen gelesen.
- **Lichter, Rollläden, Sensoren**: frei erweiterbare Listen (beliebig viele Zeilen, auch keine). Lichter erkennen automatisch, ob die Variable ein Schalter (Ein/Aus) oder ein Dimmer (Schieberegler) ist. Sensoren haben einen Typ (Fenster/Tür/Feuchtigkeit/Rauchmelder/Sirene/Sonstiges), der bestimmt, wie sie dargestellt werden.

## Funktionsweise

Dieses Modul besitzt keine der zugrunde liegenden Variablen. Schreibaktionen (Lichtschalter, Dimmer, Rollladenposition, Sonos-Steuerung, Lüftungsmodus) werden über die globale IPS-Funktion `RequestAction()` an die jeweils konfigurierte Variable weitergereicht -- keine eigene Geräteanbindung nötig.

## Kachel einrichten

Die Kachel in einer Tile-Visualization platzieren. Die Höhe richtet sich danach, wie viele Widgets/Listen-Einträge der jeweilige Raum tatsächlich hat.
