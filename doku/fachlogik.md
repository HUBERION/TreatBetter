# Fachlogik

Wie die Testversion entscheidet, wann ein Termin frei ist, und welche Daten über den Salon bekannt sind. Der Code dazu steht im Abschnitt „Logik“ von `docs/app.js`.

## Freie Termine

```
frei = Öffnungszeiten des Salons
     ∩ Arbeitszeit der Person          (derzeit = Öffnungszeiten)
     − belegte Zeiten aus Google Kalender (nur Arten, die laut Einstellung blockieren)
     − Puffer vor und nach jedem belegten Termin
     − Vorlaufzeit ab jetzt
     − alles nach dem Buchungshorizont
```

So rechnet `slotsFor(person, tag, dauer)`:

1. **Öffnungszeiten** des Tages holen (`openingFor`). Geschlossen, Feiertag oder Tag in der Vergangenheit ergeben keine Termine.
2. **Startzeiten** im Raster `rules.step` (Standard 15 Min.) erzeugen, beginnend bei der Öffnungszeit. Der Termin muss vor Ladenschluss enden: `start + dauer ≤ ende`.
3. **Vorlaufzeit:** Startzeiten früher als jetzt + `rules.lead` (Standard 30 Min.) fallen weg. Das gilt auch über Mitternacht hinaus, etwa bei „1 Tag vorher“.
4. **Konflikte:** Eine Startzeit fällt weg, wenn sie mit einem blockierenden Termin plus Puffer (`rules.buffer`, Standard 5 Min.) kollidiert:
   `start < belegt.ende + puffer` **und** `start + dauer + puffer > belegt.beginn`
5. **Horizont:** Nur Tage innerhalb von `rules.weeks` Wochen ab heute (Standard 8).

Bei **„Beliebig“** fasst `slotMap()` die freien Startzeiten aller online buchbaren Personen zusammen und merkt sich je Uhrzeit, wer frei ist. Beim Buchen bekommt den Termin die Person mit den wenigsten Buchungen an diesem Tag. Bei Gleichstand gilt die Reihenfolge in `STAFF`, also zuerst Andrea.

Die Dauer kommt aus der gewählten Leistung. Ein 60-Minuten-Termin hat daher weniger freie Startzeiten als ein 5-Minuten-Termin.

## Öffnungszeiten und Ausnahmen

Reihenfolge in `openingFor(tag)`:

1. **Manuelle Ausnahme:** Am 24.12. ist von 08:30 bis 12:00 offen (Heiliger Abend), aber nur, wenn der Wochentag regulär geöffnet ist.
2. **Feiertag**, wenn „Österreichische Feiertage automatisch schließen“ aktiv ist: geschlossen.
3. **Reguläre Öffnungszeiten** des Wochentags aus `S.hours`.

Feiertage berechnet `holidays(jahr)` für jedes Jahr selbst:

- **Feste Feiertage:** Neujahr (1.1.), Heilige Drei Könige (6.1.), Staatsfeiertag (1.5.), Mariä Himmelfahrt (15.8.), Nationalfeiertag (26.10.), Allerheiligen (1.11.), Mariä Empfängnis (8.12.), Christtag (25.12.), Stefanitag (26.12.).
- **Bewegliche Feiertage** ab Ostersonntag: Ostermontag (+1), Christi Himmelfahrt (+39), Pfingstmontag (+50), Fronleichnam (+60).

Der Kärntner Landesfeiertag (10. Oktober) ist kein allgemeiner Feiertag und daher nicht enthalten. Soll der Salon an dem Tag zu haben, wäre das eine manuelle Ausnahme.

## Termine aus dem Google Kalender

Das Team nutzt den Kalender am Android-Handy, die Termine liegen im Google-Konto (Annahme, siehe [roadmap.md](roadmap.md#offene-fragen-an-den-salon)). Aus dem Google Kalender wird nur gelesen, **wann** jemand belegt ist, nie was eingetragen ist. Welche Arten von Terminen blockieren, legt die Verwaltung unter Team & Kalender fest (`S.blockers`):

| Im Google Kalender | Schlüssel in `S.blockers` | Merkmal in der Google Calendar API | Standard |
|---|---|---|---|
| Normaler Termin, „Beschäftigt“ | `busy` | `transparency: opaque` | blockiert |
| Abwesend | `oof` | `eventType: outOfOffice` | blockiert |
| Einladung mit Antwort „Vielleicht“ | `tentative` | eigene `responseStatus: tentative` | blockiert |
| Termin als „Verfügbar“ markiert | `elsewhere` | `transparency: transparent` | blockiert nicht |
| Ganztägige Termine (z. B. Urlaub) | `allDay` | `start.date` statt `start.dateTime` | blockiert |

Die Schlüssel stammen noch aus der Outlook-Planung (`elsewhere` hieß „An anderem Ort tätig“) und bleiben gleich, damit gespeicherte Testdaten gültig bleiben.

**Achtung:** Ganztägige Termine sind in Google standardmäßig „Verfügbar“. Damit Urlaub trotzdem blockiert, wertet die Seite ganztägige Termine eigens aus (`allDay`).

Über die Seite gebuchte Termine (`kind: 'booking'`) blockieren immer.

## Buchung, Storno, Anwesenheit

- **Gastbuchung** ohne Konto. Pflichtfelder: Name, E-Mail, Telefonnummer und die Zustimmung zu den Buchungsbedingungen.
- **Bezahlung:** nur vor Ort, bar oder mit Karte. Es gibt keine Zahlungsfelder.
- **Storno-Frist:** Kostenlos stornierbar bis `rules.storno` Stunden vor Beginn (Standard 24). Danach zeigt die Seite die Telefonnummer des Salons.
- **Anwesenheit** im Tageskalender: Vergangene Termine gelten standardmäßig als „Erschienen“, außer Beispieltermine mit `noshow`. Begonnene, noch offene Termine bekommen die Knöpfe „Erschienen“ und „Nicht erschienen“, spätere zeigen „Geplant“. Weil niemand vorab zahlt, ist das die Grundlage für eine No-Show-Regel, etwa „nach 2× nicht erschienen nur noch telefonisch buchbar“.

## Tageskalender in der Verwaltung

| Darstellung | Bedeutung |
|---|---|
| Pflaume, gefüllt | über die Seite gebucht (steht im echten Betrieb auch im Google Kalender) |
| Pflaume mit gelbem Rand, „NEU“ | in diesem Browser als Test gebucht |
| Grau, gefüllt | belegt laut Google Kalender, ohne Details |
| Weiß, grau gestrichelt | im Google Kalender eingetragen, blockiert laut Einstellung aber nicht |
| Grün, gestrichelt | frei und online buchbar (Lücken ab 15 Min.) |
| Schraffiert | geschlossen bzw. außerhalb der Öffnungszeiten |
| Orange Linie | jetzt |

## Bekannte Daten über den Salon

Quelle: Treatwell-Profil des Salons, abgerufen am 13.10.2026, sowie das Schaufenster (Telefonnummern).

**Friseur Andrea Zechmeister**, August-Jaksch-Straße 1, 9020 Klagenfurt
Telefon: 0463 51 59 52 · 0676 460 48 83

**Öffnungszeiten**

| Tag | Zeit |
|---|---|
| Montag | 08:30–13:30 |
| Dienstag | 08:30–19:00 |
| Mittwoch | 08:30–13:30 |
| Donnerstag | 08:30–19:00 |
| Freitag | 07:30–20:00 |
| Samstag, Sonntag | geschlossen |

**Team:** Andrea (Friseurmeisterin, Inhaberin), Pamela (Friseurin)

**Leistungen mit bekannter Dauer und bekanntem Preis**

| Leistung | Dauer | Preis |
|---|---|---|
| Damen – Trockenhaarschnitt | 25 Min. | 27 € |
| Damen – Waschen & Legen | 30 Min. | 31 € |
| Damen – Waschen & Föhnen | 30 Min. | 34 € |
| Damen – Waschen, Schneiden & Föhnen | 1 Std. | 61 € |
| Damen – Pflege Lotion | 5 Min. | 7,50 € |
| Damen – Haarmaske | 15 Min. | 16 € |
| Herren – Trockenhaarschnitt | 20 Min. | 27 € |
| Kinder bis 10 Jahre – Haarschnitt | 20 Min. | 14 € |

**Leistungsgruppen laut Treatwell, Einzelheiten teils unbekannt**

| Gruppe | Anzahl | Preis |
|---|---|---|
| Damen – Haarschnitte & Stylings | 4 | ab 27 € |
| Herren – Haarschnitte & Stylings | 2 | ab 27 € (nur eine bekannt) |
| Kinder – Teenager – Studenten | 4 | ab 14 € (nur eine bekannt) |
| Haarkuren & Pflege | 2 | ab 7,50 € |
| Culum Natura – Pflanzenfarbe | 1 | 35 € (Dauer unbekannt) |
| Haarverlängerung | 1 | 35 € (Dauer unbekannt) |

Laut Profil außerdem: barrierefrei, vegane und natürliche Produkte, Parkplätze in der Nähe.

**Nur Beispieldaten**, nicht echt: alle Kundennamen, alle belegten Zeiten aus dem Google Kalender, die ganztägige Abwesenheit von Andrea an jedem zweiten Freitag, die „Neue Kollegin“ im Team sowie die Arbeitszeiten von Andrea und Pamela (derzeit gleich den Öffnungszeiten).
