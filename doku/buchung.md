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
- **Verschieben** (auch zur anderen Person), dabei lässt sich auch die Dauer ändern, und **Absagen**, jeweils auf Wunsch mit E-Mail an die Kundin. Beim Verschieben gibt es einen neuen Absage-Link, der alte gilt nicht mehr.
- **Kundensuche** nach Name, Telefon oder E-Mail (letzte 12 Monate und alle kommenden Termine).
- **Einstellungen:** Online-Buchung je Person pausieren (`daten/einstellungen.json`). Die Kundenseite blendet pausierte Personen sofort aus.

Nur das Dashboard liest Titel und Beschreibung von Terminen, und nur aus den beiden Buchungskalendern des Salons. Schreibende Anfragen brauchen zusätzlich den Kopf `X-Verwaltung: 1`.
