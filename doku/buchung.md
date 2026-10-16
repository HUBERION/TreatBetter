# Echte Online-Buchung (`termin/`)

Die Buchungsseite für echte Termine. Sie läuft auf dem IONOS-Webhosting des Salons unter **andreazechmeister.at/termin/** und arbeitet direkt mit dem Google Kalender des Salons. Die Testversion in `docs/` bleibt als Entwurf bestehen.

## Aufbau

```
termin/
├── index.html, app.js, style.css   ← Kundenseite (Vanilla JS, kein Build), Aussehen aus docs/
├── api.php                         ← Schnittstelle: stamm, woche, buchen, termin, storno
├── verwaltung.html, verwaltung.js   ← Dashboard für den Salon (Login mit Salon-Passwort)
├── verwaltung.php                  ← Schnittstelle des Dashboards
├── aufgaben.php                    ← täglicher Cronjob: Erinnerungen, alte Kundendaten entfernen
├── lib/
│   ├── salon.php                   ← Leistungen, Team, Öffnungszeiten, Regeln
│   ├── web.php                     ← gemeinsame Hilfsfunktionen, Einstellungen (Pausieren)
│   ├── kunden.php                  ← Kundenkartei (daten/kunden.json)
│   ├── tabelle.php                 ← Excel (.xlsx) und CSV lesen/schreiben, ohne Zusatzbibliotheken
│   ├── postfach.php                ← IMAP (nur lesend) und MIME, ohne PHP-Erweiterung „imap“
│   ├── treatwell.php               ← Treatwell-Buchungs-E-Mails auswerten und eintragen
│   ├── zeit.php                    ← Feiertage, Öffnungszeiten, freie Zeiten (reine Funktionen)
│   ├── kalender.php                ← Google Calendar API über Dienstkonto, Demo-Modus
│   └── mail.php                    ← Bestätigung, Absage, Info an den Salon
├── tests/zeit-test.php             ← Tests der Zeitberechnung: php termin/tests/zeit-test.php
└── .htaccess                       ← sperrt lib/ und tests/
```

Es gibt **keine Datenbank**. Jede Buchung ist ein Termin im Kalender „Buchungen Andrea“ bzw. „Buchungen Pamela“ im Google-Konto des Salons. Kundendaten und der Prüfwert des Storno-Links stehen in privaten Zusatzfeldern des Termins (`extendedProperties.private`). Die Verwaltung ist der Google Kalender selbst, am Salon-Handy und am Salon-PC.

## Regeln (in `lib/salon.php`)

| Regel | Wert |
|---|---|
| Startzeiten-Raster | 15 Min. |
| Puffer nach jedem belegten Termin | 5 Min. |
| Frühestens buchbar | 4 Std. ab jetzt |
| Spätestens buchbar | 3 Monate im Voraus |
| Kostenlos absagen | bis 24 Std. vorher, danach nur telefonisch |
| Andrea | Mo–Fr zu den Öffnungszeiten |
| Pamela | Mo–Fr, Termin muss bis 14:00 fertig sein |

Belegt ist alles, was im Buchungskalender der Person steht, außer Terminen auf „Verfügbar“. Ganztägige Termine blockieren immer, auch wenn sie auf „Verfügbar“ stehen (Urlaub). Feiertage wie in [fachlogik.md](fachlogik.md#öffnungszeiten-und-ausnahmen). Bei „Beliebig“ bekommt den Termin, wer an dem Tag weniger Online-Buchungen hat, bei Gleichstand Andrea.

## Google

- Google-Konto des Salons: friseur@andreazechmeister.at (kostenlos, kein Workspace)
- Google-Cloud-Projekt „TreatBetter“, Calendar API aktiviert
- Dienstkonto `buchungsseite@treatbetter.iam.gserviceaccount.com`, keine Rollen im Projekt
- Beide Buchungskalender sind mit dem Dienstkonto geteilt: „Änderungen an Terminen vornehmen“
- Der **Schlüssel** (JSON) des Dienstkontos liegt nur auf dem Server, nie im Repository

## Konfiguration auf dem Server

Liegt **außerhalb** des öffentlichen Ordners, standardmäßig `../../treatbetter-privat/config.php` von `termin/api.php` aus (also neben dem Ordner `Friseurhomepage` im Webspace). Lokal lässt sich der Pfad mit der Umgebungsvariable `TREATBETTER_CONFIG` ändern.

```php
<?php
return [
    'modus' => 'google',                       // 'demo' = erfundene Termine aus daten/demo-kalender.json
    'kalender' => [
        'andrea' => '…@group.calendar.google.com', // Kalender-ID „Buchungen Andrea“
        'pamela' => '…@group.calendar.google.com', // Kalender-ID „Buchungen Pamela“
    ],
    'schluessel' => __DIR__ . '/google-schluessel.json',
    'daten' => __DIR__ . '/daten',             // beschreibbar: Token-Zwischenspeicher, Sperren, Fehlerprotokoll
    'basis_url' => 'https://andreazechmeister.at/termin/',
    'mail_aktiv' => true,                      // false = E-Mails nur als Dateien in daten/mails/
    'mail_an_salon' => true,                   // Info-Mail an den Salon bei jeder Buchung und Absage
    'salz' => '…zufällige Zeichenkette…',      // für die gehashte IP-Adresse in der Missbrauchsbremse
    'cron_schluessel' => '…mind. 32 Zeichen…', // Schlüssel für den Aufruf von aufgaben.php durch den Cronjob
    'verwaltung_passwort' => 'y$…',         // password_hash() des Salon-Passworts fürs Dashboard
    'verwaltung_geheimnis' => '…mind. 32 Zeichen…', // signiert das Anmelde-Cookie; ändern = alle abmelden
    'postfach' => ['host' => 'imap.ionos.de', 'port' => 993, 'benutzer' => 'friseur@andreazechmeister.at', 'passwort' => ''], // Treatwell-E-Mails; leer = aus
];
```

## Lokal testen

```bash
php termin/tests/zeit-test.php
```

Für die Seite eine Konfiguration mit `'modus' => 'demo'` anlegen, `TREATBETTER_CONFIG` darauf setzen und `php -S localhost:8140 -t .` im Repository starten. Dann http://localhost:8140/termin/ öffnen. Gebucht wird in `daten/demo-kalender.json`, E-Mails landen in `daten/mails/`.

## Schutz und Datenschutz

- Titel und Inhalte fremder Termine werden nicht verwendet: Für die Berechnung zählen nur Beginn, Ende und „Verfügbar/Beschäftigt“.
- Storno-Link: zufälliger Schlüssel im Teil nach `#`, der nicht in Server-Protokollen landet. Im Kalender steht nur ein SHA-256-Prüfwert.
- Gleichzeitige Buchungen werden mit einer Dateisperre nacheinander geprüft, keine Doppelbuchung.
- Missbrauchsbremse: höchstens 5 Buchungen und 30 Storno-Aufrufe je Stunde und IP-Adresse (IP nur gehasht mit Tagessalz, nach einem Tag gelöscht), dazu ein unsichtbares Feld gegen Spam-Programme.
- Die Datenschutzerklärung der Homepage hat einen Abschnitt „Online-Terminbuchung“ (Daten, Google Kalender, Löschung nach 12 Monaten). Erst beim Livegang hochladen.

## Tägliche Aufgaben (`aufgaben.php`)

Der IONOS-Cronjob-Manager kann nur Adressen aufrufen. Deshalb ruft ein täglicher **WebCron** (morgens) `https://andreazechmeister.at/termin/aufgaben.php?k=…` auf. Der Schlüssel `k` steht als `cron_schluessel` (mindestens 32 Zeichen) in der Konfiguration; ohne ihn antwortet das Skript mit 404. Lokal geht auch `php termin/aufgaben.php`. Jeder Lauf wird in `daten/aufgaben.log` vermerkt.

1. **Erinnerung:** E-Mail an alle, die morgen einen Online-Termin haben. Danach wird der Termin mit `erinnert=1` markiert, damit keine zweite E-Mail kommt.
2. **Aufräumen:** Bei Online-Terminen, die länger als 12 Monate (`REGELN['aufbewahrung']`) zurückliegen, werden Name, E-Mail, Telefon und Storno-Prüfwert entfernt. Titel wird „Online-Termin – Leistung“.

## Noch offen

- Upload auf IONOS, Konfiguration und Schlüssel im privaten Ordner, Cronjob einrichten
- Test mit dem echten Kalender
- Beim Livegang: neue `datenschutz.html` der Homepage hochladen, Buchungsknopf auf der Homepage ergänzen

## Dashboard (`verwaltung.html`)

Für Andrea und Pamela, am Salon-Handy und am Salon-PC: **andreazechmeister.at/termin/verwaltung.html**. Damit müssen sie nicht im Google Kalender arbeiten, der bleibt aber im Hintergrund der Speicher.

- **Anmeldung:** ein gemeinsames Salon-Passwort (`verwaltung_passwort`, nur als Hash in der Konfiguration). Danach bleibt man 30 Tage angemeldet. Das Cookie `tb_verwaltung` enthält nur Ablaufzeit und Signatur (`verwaltung_geheimnis`), ist `HttpOnly`, `Secure`, `SameSite=Strict` und technisch notwendig, also ohne Cookie-Banner. Höchstens 10 Anmeldeversuche pro Stunde und IP.
- **Tag:** Andrea und Pamela nebeneinander; in eine freie Stelle tippen öffnet „Eintragen“ mit Person und Uhrzeit.
- **Woche:** sieben Tage auf einen Blick.
- **Eintragen:** Kundentermin (telefonisch) mit Leistung aus der Liste oder „Andere Tätigkeit“ mit eigener Bezeichnung; die Dauer kommt aus der Leistung oder wird selbst eingetragen (5–600 Minuten), oder Zeit blockieren (Pause, Arzttermin, Frei, Urlaub, Krank, Sonstiges, auch ganztägig über mehrere Tage, auch für beide). Bei Überschneidungen kommt eine Warnung mit „Trotzdem eintragen“.
- **Telefonische Termine** mit E-Mail-Adresse bekommen auf Wunsch Bestätigung, Absage-Link und Erinnerung wie Online-Buchungen.
- **Bearbeiten:** Kundentermine (Name, Telefon, E-Mail, Leistung bzw. eigene Tätigkeit, Dauer, Person, Datum, Uhrzeit; auf Wunsch E-Mail „Dein Termin wurde geändert“ mit neuem Absage-Link) und Blockzeiten bzw. Einträge aus dem Google Kalender (Bezeichnung, Notiz, Person, ganztägig oder mit Uhrzeit, von–bis). Und **Absagen**, jeweils auf Wunsch mit E-Mail an die Kundin. Beim Verschieben gibt es einen neuen Absage-Link, der alte gilt nicht mehr.
- **Kundensuche** nach Name, Telefon oder E-Mail (letzte 12 Monate und alle kommenden Termine).
- **Einstellungen:** Online-Buchung je Person pausieren (`daten/einstellungen.json`). Die Kundenseite blendet pausierte Personen sofort aus.

Nur das Dashboard liest Titel und Beschreibung von Terminen, und nur aus den beiden Buchungskalendern des Salons. Schreibende Anfragen brauchen zusätzlich den Kopf `X-Verwaltung: 1`.

## Kundenkartei

Stammkund:innen im Dashboard unter **Kunden**. Die Kartei liegt als `daten/kunden.json` im privaten Ordner auf dem Server (nicht bei Google), Änderungen unter Dateisperre.

- **Karte:** Name, Telefon, E-Mail, Notiz (Arbeitshinweise wie Farbrezeptur; Hinweis im Dashboard: keine Gesundheitsdaten) und Besuche.
- **Besuche:** gehören zu einem Termin im Kalender (`terminId`, werden beim Verschieben mitgezogen und beim Absagen entfernt) oder wurden von der Papierkarte **nachgetragen** (nur Vergangenheit).
- **Eintragen:** Beim Tippen des Namens erscheinen passende Karten; nach der Auswahl stehen Telefon, E-Mail und „Zuletzt: …“ da, „Gleiche Leistung“ übernimmt die letzte Leistung. Für neue Personen ist „In der Kundenkartei speichern“ vorausgewählt.
- **Termin-Detail:** „Kundenkarte“ bzw. „Als Stammkunde speichern“.
- **Online-Buchungen** werden automatisch einer Karte zugeordnet, wenn E-Mail oder Telefonnummer übereinstimmt (Telefon wird vereinheitlicht: `0664…` = `+43664…`). Neue Online-Kund:innen bekommen keine Karte von selbst.
- **Löschen:** `aufgaben.php` löscht Karten, deren letzter Besuch (bzw. Anlage) mehr als 3 Jahre zurückliegt. Die Karte zeigt das Löschdatum an. Von Hand löschen geht jederzeit.
- Die Datenschutzerklärung der Homepage hat dazu den Abschnitt „Kundenkartei“.

### Excel-Import und -Export

Unter **Kunden › Excel** im Dashboard:

- **Vorlage herunterladen:** `.xlsx` mit den Spalten Name, Telefon, E-Mail, Letzter Besuch, Letzte Leistung, Bei, Notiz und zwei erfundenen Beispielzeilen.
- **Aus Excel importieren:** `.xlsx` oder `.csv` (Komma, Semikolon oder Tabulator; UTF-8 oder Windows-Zeichensatz). Spalten werden an den Überschriften erkannt (auch Vorname/Nachname getrennt, Handy, Bemerkung, Behandlung …), sonst gilt die Reihenfolge der Vorlage. Datum als `TT.MM.JJJJ`, `TT.MM.JJ`, `JJJJ-MM-TT` oder Excel-Datumswert. Zuerst kommt eine **Vorschau** (neu / wird ergänzt / Fehler mit Zeilennummer), erst danach wird übernommen. Vorhandene Karten (gleiche E-Mail oder Telefonnummer, sonst gleicher Name ohne widersprüchliche Kontaktdaten) werden nur ergänzt: leere Felder füllen, Notiz anhängen, den letzten Besuch nachtragen, nichts überschreiben. Höchstens 3000 Zeilen und 5 MB pro Datei; das alte `.xls`-Format geht nicht.
- **Kartei als Excel exportieren:** alle Karten mit letztem Besuch, nächstem Termin, Anzahl Besuche und Löschdatum. Der Export lässt sich wieder importieren.

`lib/tabelle.php` liest und schreibt Zip und `.xlsx` selbst (nur `zlib`/`gzinflate`, keine Erweiterung `zip` nötig). Getestet mit Dateien, die Excel selbst gespeichert hat.

## Treatwell während der Bindefrist

Treatwell muss noch etwa ein Jahr parallel laufen (Stand Oktober 2026).

- **Unsere Termine → Treatwell:** In Treatwell Connect unter Team › Person › External Calendar die „Privatadresse im iCal-Format“ von „Buchungen Andrea“ bzw. „Buchungen Pamela“ eintragen (offizielle Funktion von Treatwell, nur frei/belegt). Anleitung für den Salon: `D:\Andrea Homepage\Treatwell verbinden.html`.
- **Treatwell → Dashboard, automatisch:** Treatwell gibt Buchungen nur an ausgewählte Salonprogramme weiter (Salonized, Clinic Software, Delete Waxing), nicht an Google. Deshalb liest `treatwell-abruf.php` die Benachrichtigungs-E-Mails („Neue Treatwell Buchung (Unsere Ref. T…)“) aus dem Postfach friseur@andreazechmeister.at (IMAP, nur lesend, nur Absender mit „treatwell“, letzte 14 Tage) und trägt sie ein: Leistung(en) mit Dauer, Datum/Zeit, Mitarbeiterin, Kundenname (ohne Zusatz „Neu“), E-Mail, Telefon, Bezahlstatus („Vorab bezahlt“ wird im Dashboard deutlich angezeigt), Bestellnummer als `tw`. Gleiche Bestellnummer = derselbe Termin (keine Doppelten); ein schon von Hand eingetragener Treatwell-Termin zur selben Zeit wird nur verknüpft. Stornierungen (Betreff mit „storn“) löschen den Termin, Änderungen verschieben ihn. Was sich nicht sicher lesen lässt, Überschneidungen und Postfach-Fehler erscheinen oben im Dashboard als Hinweis. Abruf durch einen eigenen Cronjob (`treatwell-abruf.php?k=…`) und zusätzlich beim Öffnen des Dashboards (höchstens alle 2 Minuten). Das Postfach-Passwort steht nur in der Konfiguration auf dem Server (`postfach.passwort`, leer = aus). Tests: `php termin/tests/treatwell-test.php` mit echten, anonymisierten Treatwell-E-Mails.
- **Treatwell → Dashboard, von Hand** (falls der Abruf aus ist): beim Eintragen „Woher kommt der Termin? › Treatwell“ wählen: `quelle=treatwell`, Titel mit „(Treatwell)“, türkis im Dashboard, keine E-Mails und keine Erinnerung von uns (macht Treatwell), Hinweis, Änderungen auch in Treatwell vorzunehmen.
- Nach der Bindefrist: Verbindung in Treatwell lösen und die geheime iCal-Adresse in Google zurücksetzen.
