# AGENTS.md – Leitfaden für KI-Assistenten

Diese Datei ist der Einstieg für KI-Coding-Assistenten (Claude Code, Codex, Cursor, Copilot und andere), die in diesem Repository arbeiten. Sie fasst zusammen, was das Projekt ist, wie der Code aufgebaut ist und welche Regeln gelten. Details stehen in [`doku/`](doku/).

## Worum es geht

**TreatBetter** ist eine Online-Terminbuchung für einen einzelnen Friseursalon, ähnlich wie Treatwell, aber eigenständig und ohne Marktplatz:

- Der Salon pflegt **allgemeine Öffnungszeiten**.
- Freie Termine entstehen erst durch **Abgleich mit den Google Kalendern** der Mitarbeiter:innen (Prinzip wie Calendly). Das Team nutzt den Kalender am Android-Handy; ursprünglich war Outlook geplant, seit Oktober 2026 gilt Google Kalender.
- Gebucht wird verbindlich, **bezahlt wird ausschließlich vor Ort** (bar oder Karte). Online-Zahlung ist bewusst nicht vorgesehen.

Erster Salon: **Friseur Andrea Zechmeister**, August-Jaksch-Straße 1, 9020 Klagenfurt. Team: Andrea (Friseurmeisterin, Inhaberin) und Pamela (Friseurin).

## Wer arbeitet mit

- **Markus Huber**: hat den Entwurf gestartet.
- **Andreas Lampl**: arbeitet seit Oktober 2026 mit, testet aus Sicht des Salons.

## Aktueller Stand

Die echte Online-Buchung ist **in Arbeit** (`termin/`, PHP auf IONOS, noch nicht live). Daneben gibt es die klickbare Testversion und einen Design-Entwurf:

| Teil | Ordner | Zustand |
|---|---|---|
| Testversion | [`docs/`](docs/) | Läuft auf GitHub Pages. Reines HTML, CSS und JavaScript, kein Build-Schritt, keine Abhängigkeiten. Alle Termine sind Beispieldaten, Eingaben bleiben im `localStorage` des Browsers. |
| Design-Entwurf | [`mockup/`](mockup/) | Quelldateien eines Claude-Design-Canvas (`.dc.html`). Laufen nicht eigenständig, nur Referenz für das Aussehen. **Für neue Arbeit `docs/` ändern, nicht `mockup/`.** |
| Salon-Homepage | [`homepage/`](homepage/) | Visitenkarten-Website des Salons zum Hochladen auf IONOS. Reines HTML und CSS, siehe [`doku/homepage.md`](doku/homepage.md). |
| Online-Buchung | [`termin/`](termin/) | Echte Buchung mit Google Kalender, PHP ohne Datenbank, läuft später unter andreazechmeister.at/termin/. Siehe [`doku/buchung.md`](doku/buchung.md). Hier sind PHP-Dateien erlaubt; Schlüssel und Konfiguration liegen nur auf dem Server. |
| Dokumentation | [`doku/`](doku/) | Architektur, Fachlogik, Roadmap, Homepage und Buchung. |

Live: **https://huberion.github.io/TreatBetter/docs/**
Sobald in den Pages-Einstellungen der Ordner `/docs` gewählt ist, liegt die App direkt unter https://huberion.github.io/TreatBetter/.

## Schnellstart

```bash
python -m http.server 8137 --directory docs
```

Dann http://localhost:8137 öffnen. Jeder andere statische Webserver geht genauso, zum Beispiel `npx serve docs`.

Die Testversion hat zwei Bereiche:

- **Kundenseite** (`#/`): Leistung wählen, Person und Tag wählen, Uhrzeit wählen, als Gast buchen, Bestätigung mit `.ics`-Download.
- **Verwaltung** (`#/verwaltung`, erreichbar über das Menü oben rechts): Tageskalender, Öffnungszeiten & Buchungsregeln, Team & Kalender.

## Projektstruktur

```
.
├── AGENTS.md              ← diese Datei
├── CLAUDE.md              ← verweist für Claude Code auf AGENTS.md
├── README.md              ← Überblick für Menschen
├── doku/
│   ├── architektur.md     ← Aufbau von docs/app.js, Zustand, Routing, Erweitern
│   ├── fachlogik.md       ← freie Termine, Öffnungszeiten, Feiertage, Google-Kalender-Termine, Salondaten
│   ├── buchung.md         ← echte Online-Buchung: Aufbau, Regeln, Google, Konfiguration
│   ├── homepage.md        ← Salon-Homepage, Upload auf IONOS
│   └── roadmap.md         ← Weg zur echten Version, offene Fragen an den Salon
├── docs/                  ← Testversion, wird von GitHub Pages ausgeliefert
│   ├── index.html         ← Gerüst, lädt style.css und app.js
│   ├── app.js             ← gesamte Logik und alle Ansichten (Vanilla JS, ein IIFE)
│   ├── style.css          ← Farben als CSS-Variablen in :root, alle Komponenten
│   ├── logo.jpg           ← Logo des Salons, Quelle der Akzentfarben
│   ├── favicon.svg
│   └── .nojekyll          ← verhindert die Jekyll-Verarbeitung auf GitHub Pages
├── homepage/              ← Salon-Homepage für IONOS (index, impressum, datenschutz, .htaccess)
├── termin/                ← echte Online-Buchung (PHP, Google Kalender), siehe doku/buchung.md
└── mockup/                ← Design-Canvas-Quellen (nur Referenz)
```

## Regeln für Änderungen

1. **Sprache:** Oberfläche auf Deutsch (Österreich). Kund:innen werden geduzt. Gegendert wird mit Doppelpunkt („Kund:innen“). Code-Kommentare auf Deutsch, Bezeichner im Stil des bestehenden Codes.
2. **Kein Build, keine Abhängigkeiten** in `docs/`, solange das Team nichts anderes entscheidet. Keine CDNs, keine Google Fonts, keine Tracker oder Analytics (DSGVO).
3. **Nur Bezahlung vor Ort.** Keine Payment-Anbieter, keine Felder für Zahlungsdaten.
4. **Kalender-Datenschutz:** Es zählt nur „frei“ oder „belegt“. Titel, Ort, Teilnehmer oder Inhalte von Terminen im Google Kalender nie abfragen, anzeigen oder speichern.
5. **Als Entwurf erkennbar lassen:** Das gelbe Band und `<meta name="robots" content="noindex, nofollow">` bleiben, solange keine echten Buchungen möglich sind. Die Seite ist öffentlich erreichbar.
6. **Benutzereingaben escapen:** Alles, was aus Eingaben oder dem Speicher kommt, mit `esc()` in HTML einsetzen. Die Ansichten werden per `innerHTML` aufgebaut.
7. **Barrierefreiheit:** echte `<button>` und `<a>`, Labels an Feldern, `aria-pressed` bzw. `role="switch"` mit `aria-checked`, Klickflächen mindestens 44 px, keine Information nur über Farbe.
8. **Speicherformat:** Neue Felder in `S` brauchen einen Standardwert in `defaults()`. Bei inkompatiblen Änderungen den Schlüssel `STORE` hochzählen (`treatbetter-entwurf-v2`), sonst laden alte Browser kaputte Daten.
9. **Keine Geheimnisse und keine echten Kundendaten** ins Repository: keine Client-Secrets, Tokens, Passwörter, echten Namen oder Telefonnummern von Kund:innen. Testdaten bleiben erfunden.
10. **Keine Fakten über den Salon erfinden.** Was unbekannt ist (siehe [`doku/roadmap.md`](doku/roadmap.md#offene-fragen-an-den-salon)), als Platzhalter kennzeichnen und nachfragen.
11. **Doku mitpflegen:** Wenn sich Verhalten, Regeln oder Struktur ändern, die passende Datei in `doku/` und diese Datei anpassen.

## Git und Deployment

- `main` ist live: GitHub Pages veröffentlicht `docs/` aus `main` nach jedem Push automatisch.
- Größere Änderungen auf einem eigenen Branch machen und als Pull Request einreichen, damit die andere Person mitlesen kann. `main` muss immer eine funktionierende Testversion enthalten.
- Commit-Nachrichten auf Deutsch: eine kurze Zusammenfassung, darunter bei Bedarf ein paar Sätze zum Warum.

## Vor jedem Commit prüfen

Es gibt keine automatischen Tests. Diese Punkte bitte im Browser durchgehen:

- [ ] Die Seite lädt ohne Fehler in der Konsole.
- [ ] Einmal komplett durchbuchen: Leistung, Person, Tag, Uhrzeit, Daten, „Verbindlich buchen“. Leere Felder werden angemahnt.
- [ ] Die Testbuchung erscheint unter Verwaltung › Kalender mit „NEU“ und blockiert die Uhrzeit auf der Kundenseite.
- [ ] Der 26. Oktober (Nationalfeiertag) ist gesperrt. Andrea ist jeden zweiten Freitag ganztägig abwesend, zum Beispiel am 23. Oktober 2026.
- [ ] Handybreite (375 px) und Desktop (1280 px) ohne waagrechtes Scrollen der Seite.
- [ ] „Testdaten zurücksetzen“ im gelben Band stellt den Ausgangszustand wieder her.

## Weiterlesen

- [`doku/architektur.md`](doku/architektur.md): wie `app.js` aufgebaut ist und wie man Ansichten, Aktionen, Leistungen oder Beispieltermine ergänzt
- [`doku/fachlogik.md`](doku/fachlogik.md): wie freie Termine berechnet werden, plus alle bekannten Salondaten
- [`doku/roadmap.md`](doku/roadmap.md): Weg zur echten Version mit Google-Kalender-Anbindung, Kosten für SMS/WhatsApp, offene Fragen an den Salon
