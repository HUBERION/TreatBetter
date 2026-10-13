# Architektur der Testversion (`docs/`)

Die Testversion ist eine Single-Page-App ohne Framework und ohne Build-Schritt. Der Browser lädt drei Dateien:

| Datei | Aufgabe |
|---|---|
| `docs/index.html` | Gerüst mit `<div id="app">`, dem Toast-Element und den Verweisen auf CSS und JS. Enthält `noindex`. |
| `docs/style.css` | Gesamtes Aussehen. Farben als CSS-Variablen in `:root`. |
| `docs/app.js` | Daten, Zustand, Logik, Ansichten und Router in einer Datei (ein IIFE, `'use strict'`). |

Es gibt keinen Server-Teil. Alles, was nach Speichern aussieht, landet im `localStorage` des Browsers.

## Aufbau von `app.js`

Die Datei ist in Abschnitte gegliedert, jeweils mit einem Kommentar `/* ---------- … ---------- */` überschrieben:

| Abschnitt | Wichtigste Namen | Inhalt |
|---|---|---|
| Hilfsfunktionen | `toMin`, `fmt`, `esc`, `keyOf`, `fromKey`, `at`, `today`, `nowMin`, `dayDiff`, `shortDate`, `longDate`, `euro`, `durText`, `weekParity` | Zeit- und Datumsrechnung, Formatierung, HTML-Escaping |
| Icons | `ICONS`, `ic(name, size, strokeWidth)` | Inline-SVG-Icons als Strings |
| Stammdaten | `SALON`, `SERVICES`, `CATS`, `GROUPS`, `STAFF`, `svc()`, `staffById()` | Salon, Leistungen, Kategorien, Team |
| Beispiel-Kalender | `B()`, `K()`, `ALLDAY()`, `TEMPLATE`, `BLOCKERS`, `SHOW_LABEL` | Wiederkehrende Beispieltermine pro Person und Wochentag |
| Feiertage | `easter()`, `holidays()`, `holidayOf()`, `manualOf()` | Österreichische Feiertage und die manuelle Ausnahme 24.12. |
| Zustand | `STORE`, `defaults()`, `load()`, `save()`, `initialUi()`, `S`, `U` | Gespeicherte Einstellungen und Buchungen, flüchtiger UI-Zustand |
| Logik | `openingFor()`, `eventsFor()`, `isBlocking()`, `activeStaff()`, `slotsFor()`, `slotMap()`, `findNextFree()`, `countBookings()`, `deadline()` | Berechnung der freien Termine, siehe [fachlogik.md](fachlogik.md) |
| Ansichten Kundenseite | `banner()`, `menuHtml()`, `viewSalon()`, `viewZeit()`, `viewBuchen()`, `viewBestaetigt()`, `viewMeine()` | Liefern HTML-Strings |
| Ansichten Verwaltung | `adminShell()`, `viewKalender()`, `switchBtn()`, `viewOeffnung()`, `viewTeam()` | Liefern HTML-Strings |
| Router und Darstellung | `ROUTES`, `TITLES`, `route()`, `render()`, `rerender()`, `go()`, `toast()`, `downloadIcs()`, `submitBooking()` | Navigation, Neuaufbau, Hinweise, Kalenderdatei, Buchung absenden |
| Aktionen | `ACT`, `CHG`, `setTime()` | Was bei Klicks und Feldänderungen passiert |

## Zeit und Datum

- **Uhrzeiten** sind überall Minuten seit Mitternacht (`toMin('08:30')` → `510`, `fmt(510)` → `"08:30"`).
- **Tage** werden als Schlüssel `YYYY-MM-DD` in lokaler Zeit gespeichert (`keyOf`, `fromKey`). Nie `toISOString()` für Tage verwenden, das rechnet in UTC und verschiebt Tage.
- `at(tag, minuten)` baut daraus ein echtes `Date`, etwa für die Storno-Frist oder die `.ics`-Datei.
- **„Jetzt“** ist die echte Uhrzeit des Browsers. Die Testversion reagiert also auf das aktuelle Datum.

## Zustand

### `S`: gespeicherter Zustand

`S` wird bei jeder Änderung mit `save()` als JSON unter dem Schlüssel `STORE` (`treatbetter-entwurf-v1`) im `localStorage` abgelegt. `load()` mischt Gespeichertes mit `defaults()`, damit fehlende Felder Standardwerte bekommen.

| Feld | Inhalt | Wirkt auf |
|---|---|---|
| `hours` | Öffnungszeiten je Wochentag, Schlüssel wie `Date.getDay()` (0 = Sonntag): `{ open, from, to }` | freie Termine, Salon-Seite |
| `rules` | `step`, `buffer`, `lead` (Minuten), `weeks` (Buchungshorizont), `storno` (Stunden), `reminder`, `noshow` | `step`, `buffer`, `lead`, `weeks`, `storno` wirken; `reminder` und `noshow` werden nur gespeichert |
| `autoHolidays` | Feiertage automatisch schließen | freie Termine, Ausnahmenliste |
| `blockers` | Welche Arten von Google-Kalender-Terminen blockieren (siehe [fachlogik.md](fachlogik.md#termine-aus-dem-google-kalender)): `busy`, `oof`, `tentative`, `elsewhere`, `allDay` | freie Termine, Tageskalender |
| `calendars` | Welche Kalender pro Person als belegt zählen (Buchungskalender im Salon-Konto, eigener Kalender), Schlüssel `person.kalender` | nur gespeichert, noch ohne Wirkung |
| `bookable` | Ob eine Person online buchbar ist | Personenauswahl, freie Termine |
| `bookings` | Testbuchungen: `{ id, key, start, end, staff, svc, name, mail, tel }` | alles |
| `status` | Anwesenheit je Termin-ID: `da`, `weg` oder `offen` | Tageskalender |
| `lastBooking` | ID der zuletzt gebuchten Testbuchung | Bestätigungsseite |

Neue Felder bekommen einen Standardwert in `defaults()`. Bei inkompatiblen Änderungen den Schlüssel `STORE` auf `-v2` usw. hochzählen.

### `U`: flüchtiger UI-Zustand

Wird nicht gespeichert und beim Neuladen zurückgesetzt: gewählte Kategorie (`cat`), Menü offen (`menu`), gewählte Leistung (`svc`), Person (`staff`, `'any'` = Beliebig), angezeigte Woche (`week`), Tag (`day`), Uhrzeit (`slot`), Formularwerte und Fehler (`form`, `errors`) sowie der Tag im Verwaltungskalender (`adminDay`).

## Routing

Hash-Routing, damit GitHub Pages ohne Server-Konfiguration auskommt:

| Hash | Ansicht | Bemerkung |
|---|---|---|
| `#/` | `viewSalon` | Salon-Seite mit Leistungen und Menü |
| `#/termin` | `viewZeit` | braucht `U.svc`, sonst Weiterleitung auf `#/` |
| `#/buchen` | `viewBuchen` | braucht `U.svc` und `U.slot` |
| `#/bestaetigt` | `viewBestaetigt` | zeigt `S.lastBooking` |
| `#/meine` | `viewMeine` | alle Testbuchungen dieses Browsers |
| `#/verwaltung` | `viewKalender` | Tageskalender |
| `#/verwaltung/oeffnungszeiten` | `viewOeffnung` | Öffnungszeiten, Ausnahmen, Buchungsregeln |
| `#/verwaltung/team` | `viewTeam` | Salon-Konto, Team, Google-Kalender-Einstellungen |

Routen, die mit `verwaltung` beginnen, werden in `adminShell()` mit Seitenleiste gerendert, alle anderen in der schmalen Kundenspalte (`.cust` › `.phone`).

## Darstellung

- Jede `view…()`-Funktion gibt einen HTML-String zurück. `render()` setzt Band und Ansicht per `innerHTML` in `#app`.
- **Alles, was aus Eingaben oder dem Speicher kommt, geht durch `esc()`.**
- `render()` setzt bei einem Routenwechsel den Fokus auf die `<h1>` der neuen Seite (für Screenreader und Tastatur).
- `rerender()` baut die aktuelle Seite neu und stellt den Fokus auf das Element mit gleichem `data-act`/`data-change` und `data-arg` wieder her. Für Aktionen auf derselben Seite immer `rerender()` verwenden, für Seitenwechsel `go('#/…')`.
- Kurze Hinweise zeigt `toast('…')` an.

## Ereignisse

Es gibt keine einzelnen Event-Listener an Elementen. `#app` hört zentral zu (Event-Delegation):

| Attribut im HTML | Reagiert auf | Handler |
|---|---|---|
| `data-act="name"` und optional `data-arg="…"` | Klick | `ACT[name](arg, element, event)` |
| `data-st="…"` | zusätzlicher Wert für `ACT.status` | – |
| `data-change="name"` und `data-arg` | `change`-Event (Felder, Selects, Checkboxen) | `CHG[name](arg, element)` |
| `<form data-submit="book">` | `input` und `submit` | hält `U.form` aktuell, ruft `submitBooking()` auf |
| `<details class="hours">` | `toggle` | merkt sich den Zustand in `U.hoursOpen` |

Escape schließt das Menü. `hashchange` schließt das Menü, rendert neu und scrollt nach oben.

## Rezepte

**Neue Ansicht**
1. Eine Funktion `viewNeu()` schreiben, die einen HTML-String zurückgibt.
2. In `ROUTES` und `TITLES` eintragen, zum Beispiel `'verwaltung/leistungen': viewNeu`.
3. Verlinken: `<a href="#/verwaltung/leistungen">`. In der Verwaltung den Eintrag in `adminShell()` von `soon(…)` auf `link(…)` umstellen.

**Neue Aktion**
1. Im HTML `data-act="meineAktion" data-arg="…"` an einen `<button type="button">` hängen.
2. `ACT.meineAktion = (arg, el) => { …; save(); rerender(); }` ergänzen.

**Neue Leistung:** Eintrag in `SERVICES` mit `id`, `name`, `min` (Dauer in Minuten), `price` (Euro, Zahl) und `cats` (Kategorien aus `CATS`).

**Beispieltermine ändern:** `TEMPLATE[person][wochentag]` ist eine Liste aus
- `B('10:00', '11:30', show)`: belegt laut Google Kalender, `show` = `'busy'` (Standard), `'tentative'` („Vielleicht“), `'oof'` (Abwesend) oder `'elsewhere'` („Verfügbar“)
- `K('13:30', 'T. Wagner', 'herren-trocken', extra)`: Buchung über die Seite; Ende = Beginn + Dauer der Leistung
- `ALLDAY('oof', extra)`: ganztägiger Termin im Google Kalender

`extra` kann `weeks: 'even' | 'odd'` (nur jede zweite Woche, berechnet von `weekParity()`; der 23. Oktober 2026 liegt in einer `odd`-Woche), `noshow: true` (Beispiel für „Nicht erschienen“) sowie `label`, `price` und `min` zum Überschreiben enthalten. Kundennamen bleiben erfunden.

**Farben und Abstände:** CSS-Variablen in `:root` von `style.css` (`--plum` ist die Akzentfarbe, `--grad` der Violett-Verlauf aus dem Logo für Hauptbuttons und Auswahl, `--serif` die Schrift für Überschriften, `--ground` der Hintergrund). Neue Farben dort anlegen statt fest in Komponenten schreiben.

## Bekannte Grenzen

- Kein Backend: Buchungen, Einstellungen und Anwesenheiten gelten nur im jeweiligen Browser.
- Eigene Arbeitszeiten pro Person, Pausen, manuelle Termine, Buchungsliste und Leistungsverwaltung sind nur angedeutet. Die Knöpfe zeigen einen Hinweis.
- `calendars`, `writeTo`, `reminder` und `noshow` werden gespeichert, haben aber noch keine Wirkung.
- Keine automatischen Tests. Die Checkliste steht in [`AGENTS.md`](../AGENTS.md#vor-jedem-commit-prüfen).
