# Roadmap

Wo das Projekt steht und was für eine echte Version nötig wäre. Die Vorschläge unten sind **noch nicht entschieden**. Bitte vor größeren Schritten mit dem Team abstimmen.

## Phasen

| Phase | Inhalt | Stand |
|---|---|---|
| 1. Entwurf | Design-Canvas (`mockup/`) und klickbare Testversion (`docs/`) | erledigt (Oktober 2026) |
| 2. Rückmeldung | Salon testet die Testversion, offene Fragen klären (siehe unten) | läuft |
| 3. Echte Version | Backend, Outlook-Anbindung, E-Mails, Login für das Team | offen |
| 4. Betrieb | Domain, Datenschutz und Impressum, Umstieg von Treatwell oder Parallelbetrieb | offen |

## Offene Fragen an den Salon

- [ ] **Outlook-Konten:** Haben Andrea und Pamela Microsoft-365-Geschäftskonten oder private Outlook.com-Konten? Das entscheidet über die technische Anbindung (siehe unten).
- [ ] **Arbeitszeiten:** Arbeiten beide immer zu den Öffnungszeiten, oder gibt es feste eigene Zeiten bzw. Pausen?
- [ ] **Leistungen:** Welche sind die zweite Herren-Leistung und die drei weiteren Kinder-, Teenager- und Studenten-Leistungen? Wie lange dauern Pflanzenfarbe und Haarverlängerung, und sollen die überhaupt online buchbar sein?
- [ ] **Wer macht was:** Bieten beide alle Leistungen an?
- [ ] **Regeln:** Raster, Puffer, Vorlaufzeit, Buchungshorizont, Storno-Frist und No-Show-Regel bestätigen (Vorschläge in der Testversion).
- [ ] **Erinnerungen:** nur per E-Mail oder auch per SMS bzw. WhatsApp? Kosten siehe [Benachrichtigungen per SMS oder WhatsApp](#benachrichtigungen-per-sms-oder-whatsapp). Dafür bitte schätzen: Wie viele Termine pro Monat würden online gebucht?
- [ ] **Treatwell:** Bleibt Treatwell parallel? Dann müssen Treatwell-Buchungen ebenfalls in Outlook landen, sonst drohen Doppelbuchungen.
- [ ] **Logo:** Das Logo liegt als JPG mit weißem Hintergrund vor (`docs/logo.jpg`). Gibt es es auch als SVG oder PNG mit transparentem Hintergrund?
- [ ] **Domain:** Wo soll die Buchungsseite laufen (eigene Domain, Unterseite einer bestehenden Website)?

## Vorschlag für die echte Version

### Bausteine

| Baustein | Aufgabe |
|---|---|
| Buchungsseite | die heutige Kundenseite, später mit echtem Backend |
| Verwaltung | die heutigen Verwaltungsseiten, mit Login |
| Backend (API) | freie Termine berechnen, Buchungen anlegen und stornieren, E-Mails auslösen |
| Datenbank | Salon, Leistungen, Team, Regeln, Buchungen (Kundendaten so knapp wie möglich) |
| Outlook-Anbindung | frei/belegt lesen, Buchungen als Termine eintragen, Änderungen mitbekommen |
| E-Mail-Versand | Bestätigung, Erinnerung, Storno, jeweils mit persönlichem Link statt Kundenkonto |
| SMS/WhatsApp (optional) | Erinnerung am Vortag, je nach Entscheidung des Salons (siehe unten) |

Die Berechnung der freien Termine aus [fachlogik.md](fachlogik.md) bleibt gleich. Nur die belegten Zeiten kommen dann aus Outlook statt aus `TEMPLATE`.

### Outlook über Microsoft Graph

- **Frei/belegt lesen:** `POST /users/{id}/calendar/getSchedule` liefert pro Person Zeitblöcke mit Status (`free`, `tentative`, `busy`, `oof`, `workingElsewhere`) und keine Inhalte. Das passt genau zu `S.blockers`. Laut Microsoft-Dokumentation funktioniert `getSchedule` nur mit Geschäfts- oder Schulkonten. Bei privaten Outlook.com-Konten müsste man stattdessen `calendarView` lesen und Inhalte bewusst verwerfen. **Vor der Umsetzung prüfen.**
- **Buchung eintragen:** `POST /users/{id}/events` im gewählten Kalender, mit `transactionId`, damit doppelte Anfragen keinen doppelten Termin erzeugen.
- **Änderungen mitbekommen:** Change Notifications (Webhooks) auf die Kalender abonnieren. Abos laufen nach einigen Tagen ab und müssen regelmäßig erneuert werden. Zusätzlich vor jeder Buchung frisch prüfen.
- **Doppelbuchungen verhindern:** Beim Absenden die Zeit erneut gegen Outlook und die Datenbank prüfen und die Buchung in einer Transaktion anlegen.
- **Berechtigungen:** Entweder meldet sich jede Person einmal selbst an (delegierte Rechte wie `Calendars.ReadWrite`, `offline_access`). Oder eine App-Registrierung im Tenant des Salons mit Anwendungsrechten, beschränkt auf die Postfächer des Teams. Das entscheidet sich mit der Frage nach den Kontotypen.
- **Login für die Verwaltung:** Anmeldung mit dem Microsoft-Konto des Teams liegt nahe, weil die Konten ohnehin verbunden werden.
- **Geheimnisse** (Client-Secret, Tokens) gehören in die Umgebung bzw. einen Secret-Speicher, nie ins Repository.

### Benachrichtigungen per SMS oder WhatsApp

Bestätigung und Erinnerung per E-Mail sind praktisch gratis. SMS oder WhatsApp kosten pro Nachricht und brauchen das Backend, die Testversion kann nichts verschicken. Stand der Preise: Oktober 2026, netto ohne 20 % USt. Preise ändern sich, vor einer Entscheidung beim Anbieter nachsehen.

| Weg | Anbieter | pro Nachricht | Grundgebühr |
|---|---|---|---|
| SMS | [websms](https://www.websms.com/de-at/preise) (Österreich) | 4,5 Cent | ab 19 € pro Monat, monatlich kündbar |
| SMS | [Twilio](https://www.twilio.com/en-us/sms/pricing/at) | ca. 9 Cent ($0,0979) | keine |
| SMS | [BulkGate](https://www.bulkgate.com/en/pricing/sms/at/austria/) | ca. 1,7–2,2 Cent | je nach Tarif |
| WhatsApp | WhatsApp Business Platform von Meta, über einen Anbieter (z. B. [websms](https://www.websms.com/blog/whatsapp-business-kosten)) | ca. 1,4 Cent (Kategorie „Utility“, Österreich) | plus Gebühr des Anbieters |
| WhatsApp per Klick | Button in der Verwaltung öffnet WhatsApp mit fertigem Text, das Team sendet selbst | 0 € | keine, aber nicht automatisch |

**Rechenbeispiel** (Annahme, nicht vom Salon bestätigt): 200 Online-Buchungen pro Monat, je eine Bestätigung und eine Erinnerung am Vortag, also 400 Nachrichten.

| Variante | Kosten pro Monat (netto) |
|---|---|
| SMS über websms | 400 × 4,5 Cent = 18 € + 19 € Grundgebühr ≈ **37 €** |
| SMS über Twilio | ≈ **36 €** |
| WhatsApp (Meta-Gebühr) | 400 × 1,4 Cent ≈ **6 €** plus Anbietergebühr |
| Nur Erinnerungs-SMS über websms (200 Nachrichten) | 9 € + 19 € Grundgebühr ≈ **28 €** |

Worauf es ankommt:

- Eine SMS hat höchstens 160 Zeichen, längere Texte zählen doppelt.
- WhatsApp ist pro Nachricht am günstigsten, braucht aber ein Firmenkonto bei Meta und freigegebene Textvorlagen. Nicht alle Kund:innen haben WhatsApp, und der Datenschutz mit Meta ist heikler. Meta passt die Preise bis zu viermal im Jahr an.
- Für beide Wege braucht es beim Buchen eine Zustimmung der Kund:innen (DSGVO) und einen Auftragsverarbeitungsvertrag mit dem Anbieter.
- **Vorschlag:** Bestätigung per E-Mail, dazu eine Erinnerungs-SMS am Vortag. Die Erinnerung senkt die Zahl der nicht erschienenen Kund:innen am meisten.

### Datenschutz und Recht (Österreich)

- Datenschutzerklärung und Impressum bzw. Offenlegung auf der Buchungsseite.
- Auftragsverarbeitungsverträge mit Hosting und E-Mail-Dienst, Hosting möglichst in der EU.
- Nur nötige Kundendaten speichern (Name, E-Mail, Telefon) und nach einer festgelegten Frist löschen.
- Aus Outlook nur frei/belegt verarbeiten.
- Keine Google Fonts, Tracker oder CDNs ohne Einwilligung.

### Was bewusst nicht kommt

- Keine Online-Zahlung und keine Anzahlung.
- Kein Marktplatz für mehrere Salons, solange das nicht ausdrücklich gewünscht ist.
- Keine Kundenkonten mit Passwort. Termine verwaltet man über den persönlichen Link aus der E-Mail.
