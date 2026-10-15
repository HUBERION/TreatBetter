# Echte Online-Buchung (`termin/`)

Die Buchungsseite für echte Termine. Sie läuft auf dem IONOS-Webhosting des Salons unter **andreazechmeister.at/termin/** und arbeitet direkt mit dem Google Kalender des Salons. Die Testversion in `docs/` bleibt als Entwurf bestehen.

## Aufbau

```
termin/
├── index.html, app.js, style.css   ← Kundenseite (Vanilla JS, kein Build), Aussehen aus docs/
├── api.php                         ← Schnittstelle: stamm, woche, buchen, termin, storno
├── lib/
│   ├── salon.php                   ← Leistungen, Team, Öffnungszeiten, Regeln
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
- **Vor dem Livegang:** `datenschutz.html` der Homepage um die Online-Buchung ergänzen (welche Daten, Google als Auftragsverarbeiter, Speicherdauer).

## Noch offen

- Erinnerungs-E-Mail am Vortag (Cronjob bei IONOS)
- Automatisches Löschen alter Kundendaten aus vergangenen Terminen
- Datenschutzerklärung ergänzen, Upload auf IONOS, Test mit echtem Kalender
