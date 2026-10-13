# TreatBetter

Online-Terminbuchung für Friseure, ähnlich wie Treatwell, aber für einen einzelnen Salon:

- Der Salon legt allgemeine Öffnungszeiten fest.
- Freie Termine werden mit den Outlook-Kalendern der Mitarbeiter:innen abgeglichen (ähnlich wie Calendly).
- Gebucht wird verbindlich, bezahlt wird nur vor Ort.

## Testversion (GitHub Pages)

`docs/` enthält eine eigenständige Version ohne Build-Schritt (HTML, CSS, JavaScript), die direkt über GitHub Pages läuft:
**https://huberion.github.io/TreatBetter/**

- Kundenseite: Leistung wählen, Person und Uhrzeit wählen, als Gast buchen (Bezahlung nur vor Ort), Bestätigung mit Kalender-Download.
- Verwaltung über das Menü oben rechts: Tageskalender, Öffnungszeiten & Regeln, Team & Outlook.
- Testbuchungen erscheinen sofort im Verwaltungskalender. Geänderte Öffnungszeiten, Regeln und Outlook-Einstellungen wirken sofort auf die freien Termine.
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
       − belegte Zeiten aus Outlook (Free/Busy)
       − Puffer nach jedem Termin
       − Vorlaufzeit
```

Geplante Anbindung: Microsoft Graph `getSchedule` für Frei/Belegt, Change Notifications für Änderungen. Jede Buchung wird als Termin in den Outlook-Kalender der Person geschrieben.
