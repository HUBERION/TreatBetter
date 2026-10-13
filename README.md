# TreatBetter

Online-Terminbuchung für Friseure, ähnlich wie Treatwell, aber für einen einzelnen Salon:

- Der Salon legt allgemeine Öffnungszeiten fest.
- Freie Termine werden mit den Outlook-Kalendern der Mitarbeiter:innen abgeglichen (ähnlich wie Calendly).
- Gebucht wird verbindlich, bezahlt wird nur vor Ort.

## Mockup

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
