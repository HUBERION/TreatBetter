# TreatBetter

Online-Terminbuchung für Friseure, ähnlich wie Treatwell, aber für einen einzelnen Salon:

- Der Salon legt allgemeine Öffnungszeiten fest.
- Freie Termine werden mit den Google Kalendern der Mitarbeiter:innen abgeglichen (ähnlich wie Calendly), also mit dem Kalender, den sie am Handy nutzen.
- Gebucht wird verbindlich, bezahlt wird nur vor Ort.

## Mitarbeit

- **[AGENTS.md](AGENTS.md)**: Einstieg für KI-Assistenten und alle, die am Code arbeiten (Regeln, Struktur, Checkliste)
- **[doku/architektur.md](doku/architektur.md)**: Aufbau der Testversion
- **[doku/fachlogik.md](doku/fachlogik.md)**: Berechnung der freien Termine, bekannte Salondaten
- **[doku/roadmap.md](doku/roadmap.md)**: Weg zur echten Version, offene Fragen an den Salon

## Testversion (GitHub Pages)

`docs/` enthält eine eigenständige Version ohne Build-Schritt (HTML, CSS, JavaScript), die direkt über GitHub Pages läuft:
**https://huberion.github.io/TreatBetter/docs/** (nach dem Umstellen der Pages-Quelle auf `/docs` direkt unter https://huberion.github.io/TreatBetter/)

- Kundenseite: Leistung wählen, Person und Uhrzeit wählen, als Gast buchen (Bezahlung nur vor Ort), Bestätigung mit Kalender-Download.
- Verwaltung über das Menü oben rechts: Tageskalender, Öffnungszeiten & Regeln, Team & Kalender.
- Testbuchungen erscheinen sofort im Verwaltungskalender. Geänderte Öffnungszeiten, Regeln und Kalender-Einstellungen wirken sofort auf die freien Termine.
- Alles läuft nur im Browser und wird im `localStorage` gespeichert. Es wird nichts verschickt. „Testdaten zurücksetzen“ im gelben Band stellt den Ausgangszustand wieder her.
- Die Seite ist mit `noindex` markiert, damit Suchmaschinen sie nicht aufnehmen.

Einrichten: Repository → Settings → Pages → „Deploy from a branch“, Branch `main`, Ordner `/docs`.

Lokal ansehen:

```
python -m http.server 8137 --directory docs
```

## Mockup (Design-Canvas)

Erster klickbarer Entwurf für **Friseur Andrea Zechmeister**, Klagenfurt. Alle Termine und Kalenderdaten sind Beispieldaten.

Ansehen: https://claude.ai/artifact/V2vcPDKFz1K5Senyg39XJn (privat, muss über das Share-Menü freigegeben werden)

`mockup/project/` enthält die Quelldateien des Design-Canvas. Die `.dc.html`-Dateien laufen nur im Canvas und nicht als eigenständige Webseiten.

| Datei | Inhalt |
|---|---|
| `Main.dc.html` | Salon-Seite mit Leistungen und Menü |
| `Zeit.dc.html` | Termin wählen; freie Zeiten werden aus Öffnungszeiten und belegten Outlook-Zeiten berechnet |
| `Buchen.dc.html` | Gastbuchung, Bezahlung nur vor Ort |
| `Bestaetigt.dc.html` | Bestätigung |
| `Admin-Kalender.dc.html` | Tageskalender für den Salon |
| `Admin-Oeffnungszeiten.dc.html` | Öffnungszeiten, Ausnahmen, Buchungsregeln |
| `Admin-Team.dc.html` | Team und Outlook-Anbindung |

`mockup/assets/salonfoto.jpg` ist das Schaufensterfoto aus dem Treatwell-Profil des Salons.

## Logik für freie Termine

```
frei = Öffnungszeiten ∩ Arbeitszeit der Person
       − belegte Zeiten aus dem Google Kalender (nur frei/belegt)
       − Puffer nach jedem Termin
       − Vorlaufzeit
```

Geplante Anbindung: Google Calendar API, Push Notifications für Änderungen. Jede Buchung wird als Termin in den Google Kalender der Person geschrieben und erscheint damit sofort am Handy. Details in [doku/roadmap.md](doku/roadmap.md).

Hinweis: Das Mockup in `mockup/` stammt noch aus der Outlook-Planung.
