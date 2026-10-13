# Roadmap

Wo das Projekt steht und was für eine echte Version nötig wäre. Die Vorschläge unten sind **noch nicht entschieden**. Bitte vor größeren Schritten mit dem Team abstimmen.

## Phasen

| Phase | Inhalt | Stand |
|---|---|---|
| 1. Entwurf | Design-Canvas (`mockup/`) und klickbare Testversion (`docs/`) | erledigt (Oktober 2026) |
| 2. Rückmeldung | Salon testet die Testversion, offene Fragen klären (siehe unten) | läuft |
| 3. Echte Version | Backend, Google-Kalender-Anbindung, E-Mails, Login für das Team | offen |
| 4. Betrieb | Domain, Datenschutz und Impressum, Umstieg von Treatwell oder Parallelbetrieb | offen |

## Offene Fragen an den Salon

- [ ] **Kalender:** Andrea und Pamela nutzen den Kalender am Android-Handy. Wir gehen davon aus, dass die Termine im **Google-Konto** liegen (Entscheidung Oktober 2026). Bitte am Handy prüfen: Termin öffnen, bearbeiten, dort steht der Kalender. Steht eine @gmail.com-Adresse da, passt es. Steht „Samsung Calendar“, „Telefon“ oder „Mein Kalender“ da, liegen die Termine nur am Handy bzw. bei Samsung und müssen ins Google-Konto verschoben werden, sonst ist keine Anbindung möglich. Empfohlen ist ein eigenes Google-Konto für den Salon, siehe [Empfohlener Aufbau](#empfohlener-aufbau-ein-google-konto-für-den-salon). Gibt es schon eine Gmail-Adresse des Salons, die man dafür nehmen kann?
- [ ] **Arbeitszeiten:** Arbeiten beide immer zu den Öffnungszeiten, oder gibt es feste eigene Zeiten bzw. Pausen?
- [ ] **Leistungen:** Welche sind die zweite Herren-Leistung und die drei weiteren Kinder-, Teenager- und Studenten-Leistungen? Wie lange dauern Pflanzenfarbe und Haarverlängerung, und sollen die überhaupt online buchbar sein?
- [ ] **Wer macht was:** Bieten beide alle Leistungen an?
- [ ] **Regeln:** Raster, Puffer, Vorlaufzeit, Buchungshorizont, Storno-Frist und No-Show-Regel bestätigen (Vorschläge in der Testversion).
- [ ] **Erinnerungen:** nur per E-Mail oder auch per SMS bzw. WhatsApp? Kosten siehe [Benachrichtigungen per SMS oder WhatsApp](#benachrichtigungen-per-sms-oder-whatsapp). Dafür bitte schätzen: Wie viele Termine pro Monat würden online gebucht?
- [ ] **Treatwell:** Bleibt Treatwell parallel? Dann müssen Treatwell-Buchungen ebenfalls im Google Kalender landen, sonst drohen Doppelbuchungen.
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
| Google-Kalender-Anbindung | frei/belegt lesen, Buchungen als Termine eintragen, Änderungen mitbekommen |
| E-Mail-Versand | Bestätigung, Erinnerung, Storno, jeweils mit persönlichem Link statt Kundenkonto |
| SMS/WhatsApp (optional) | Erinnerung am Vortag, je nach Entscheidung des Salons (siehe unten) |

Die Berechnung der freien Termine aus [fachlogik.md](fachlogik.md) bleibt gleich. Nur die belegten Zeiten kommen dann aus dem Google Kalender statt aus `TEMPLATE`.

### Google Kalender über die Google Calendar API

Entschieden im Oktober 2026: Das Team nutzt den Kalender am Android-Handy, die Termine liegen (vermutlich) im Google-Konto. Was dort eingetragen ist, sieht man genauso am Handy, unter calendar.google.com und in Outlook, falls dort die Google-Adresse eingebunden ist. Die Buchungsseite spricht aber direkt mit Google; über Microsoft Graph wäre ein in Outlook eingebundener Google-Kalender nicht erreichbar.

- **Frei/belegt lesen:** `events.list` je Kalender, eingeschränkt mit `fields` auf `start`, `end`, `transparency`, `eventType`, `status` und die eigene Antwort (`attendees(self,responseStatus)`). Titel, Ort, Beschreibung und Teilnehmer werden gar nicht erst abgefragt. Daraus ergeben sich die Arten aus [fachlogik.md](fachlogik.md#termine-aus-dem-google-kalender). Einfacher, aber gröber wäre `freeBusy.query`: liefert nur belegte Zeiträume, ohne Unterscheidung nach Abwesend, „Vielleicht“ oder ganztägig, und ganztägige Termine sind meist „Verfügbar“ und fehlen dann.
- **Buchung eintragen:** `events.insert` im gewählten Kalender, mit eigener Event-ID aus der Buchungs-ID, damit doppelte Anfragen keinen doppelten Termin erzeugen. Der Termin erscheint sofort am Handy.
- **Änderungen mitbekommen:** Push Notifications (`events.watch`, Webhook auf eine HTTPS-Adresse des Backends). Die Kanäle laufen ab und müssen regelmäßig erneuert werden; danach mit `syncToken` nur die Änderungen holen. Zusätzlich vor jeder Buchung frisch prüfen.
- **Doppelbuchungen verhindern:** Beim Absenden die Zeit erneut gegen den Google Kalender und die Datenbank prüfen und die Buchung in einer Transaktion anlegen.
- **Berechtigungen:** Nur das Salon-Konto verbindet sich einmal per OAuth (Rechte etwa `calendar.events` und `calendar.readonly` bzw. enger, Zugriff „offline“ für einen Refresh-Token), siehe [Empfohlener Aufbau](#empfohlener-aufbau-ein-google-konto-für-den-salon). Andrea und Pamela verbinden nichts selbst.
- **Google-Cloud-Projekt:** Für OAuth braucht es ein Projekt in der Google Cloud Console mit Zustimmungsbildschirm. Solange die App im Modus „Testing“ ist, laufen Refresh-Tokens nach 7 Tagen ab, für den Dauerbetrieb muss sie also veröffentlicht werden. Kalender-Rechte gelten als „sensibel“. Weil sich nur das eigene Konto des Salons anmeldet, reicht voraussichtlich eine veröffentlichte, nicht geprüfte App (beim Anmelden erscheint dann ein Warnhinweis). **Vor der Umsetzung prüfen.**
- **Login für die Verwaltung:** Anmeldung mit Google liegt nahe. Wer in die Verwaltung darf, legt eine Liste erlaubter Adressen fest (Salon, Andrea, Pamela).
- **Geheimnisse** (Client-Secret, Tokens) gehören in die Umgebung bzw. einen Secret-Speicher, nie ins Repository.
- **Kosten:** Die Google Calendar API ist für diese Nutzung kostenlos.

### Empfohlener Aufbau: ein Google-Konto für den Salon

Statt dass jede Person ihr eigenes Google-Konto mit der Buchungsseite verbindet, gibt es **ein Google-Konto für den Salon**. Nur dieses ist mit der Buchungsseite verbunden.

```
Salon-Konto (z. B. eine neue Gmail-Adresse des Salons)
 ├─ Kalender „Buchungen Andrea“   ← Buchungsseite trägt hier ein, Andrea sieht ihn am Handy
 ├─ Kalender „Buchungen Pamela“   ← Buchungsseite trägt hier ein, Pamela sieht ihn am Handy
 ├─ Andreas eigener Kalender      ← von Andrea freigegeben: nur frei/beschäftigt
 └─ Pamelas eigener Kalender      ← von Pamela freigegeben: nur frei/beschäftigt
```

Belegt ist eine Person, wenn in ihrem Buchungskalender **oder** in ihrem eigenen Kalender etwas eingetragen ist. Das passt zu den Feldern „Belegt prüfen in“ und „Neue Buchungen eintragen in“ unter Verwaltung › Team & Kalender.

**Warum so:**

- **Nur eine Verbindung:** Der Salon meldet sich einmal an. Keine Anmeldung pro Person, weniger Aufwand mit der Prüfung durch Google.
- **Datenschutz:** Mit der Freigabe „Nur frei/beschäftigt“ sieht das Salon-Konto technisch keine Inhalte der privaten Termine, nicht nur weil die Seite sie nicht abfragt.
- **Sauber getrennt:** Online-Buchungen stehen in eigenen Kalendern, nicht zwischen privaten Terminen. Verlässt jemand den Salon, endet die Freigabe, die Buchungen bleiben beim Salon.

**Nicht empfohlen:** ein einziger gemeinsamer Kalender für beide. Dann ließe sich nur über die Titel der Termine erkennen, wer belegt ist, und Titel liest die Seite nicht.

**Einschränkung:** Bei der Freigabe „Nur frei/beschäftigt“ liefert Google aus den eigenen Kalendern nur belegte Zeiträume (`freeBusy.query`), ohne Unterscheidung nach Abwesend, „Vielleicht“ oder ganztägig. Die Einstellung „Was blockiert einen Termin?“ wirkt dann nur auf die Buchungskalender. Termine, die als „Verfügbar“ markiert sind, zählen als frei. Das betrifft vor allem **ganztägige Termine wie Urlaub, die in Google standardmäßig „Verfügbar“ sind**: Urlaub bitte als „Beschäftigt“ eintragen, sonst ist man online buchbar. Alternative wäre die Freigabe „Alle Termindetails anzeigen“, dann sieht das Salon-Konto aber auch die Inhalte.

#### Anleitung zum Einrichten

Die Freigabe-Einstellungen gibt es nur im Browser unter calendar.google.com, nicht in der Handy-App.

**Salon (einmalig):**

1. Ein Google-Konto für den Salon anlegen (oder ein vorhandenes nutzen, das nur der Salon verwendet).
2. In calendar.google.com links bei „Weitere Kalender“ auf **+** → **Neuen Kalender erstellen**: „Buchungen Andrea“. Dasselbe für „Buchungen Pamela“.
3. Beim Kalender „Buchungen Andrea“ auf die drei Punkte → **Einstellungen und Freigabe** → **Für bestimmte Personen oder Gruppen freigeben** → Andreas Gmail-Adresse eintragen, Berechtigung **„Änderungen an Terminen vornehmen“**. Dasselbe für Pamela mit „Buchungen Pamela“.

**Andrea und Pamela (jeweils einmalig):**

4. Die Einladungs-E-Mail des Salons öffnen und den Kalender hinzufügen.
5. In calendar.google.com beim eigenen Kalender auf die drei Punkte → **Einstellungen und Freigabe** → **Für bestimmte Personen oder Gruppen freigeben** → die Adresse des Salon-Kontos eintragen, Berechtigung **„Nur Verfügbarkeit anzeigen (Details ausblenden)“**.
6. Am Handy in der Kalender-App unter Einstellungen prüfen, dass „Buchungen Andrea“ bzw. „Buchungen Pamela“ angezeigt und synchronisiert wird. In der Google-Kalender-App geht das meist von selbst, in der Samsung-Kalender-App muss man den Kalender eventuell einschalten.

**Zum Schluss** verbindet der Salon in der Verwaltung der Buchungsseite einmal sein Google-Konto (kommt mit der echten Version).

**Nicht geeignet:** einen Kalender nur über einen ICS-Link abonnieren. Das geht nur in eine Richtung und mit Verzögerung (bei Google teils Stunden), Doppelbuchungen wären die Folge.

**Alternative Outlook:** Falls doch Microsoft-Konten genutzt werden, geht dasselbe über Microsoft Graph (`getSchedule` für frei/belegt, `events` zum Eintragen, Change Notifications). Die Fachlogik bleibt gleich, nur die Anbindung wechselt.

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
- Aus dem Google Kalender nur frei/belegt verarbeiten.
- Keine Google Fonts, Tracker oder CDNs ohne Einwilligung.

### Was bewusst nicht kommt

- Keine Online-Zahlung und keine Anzahlung.
- Kein Marktplatz für mehrere Salons, solange das nicht ausdrücklich gewünscht ist.
- Keine Kundenkonten mit Passwort. Termine verwaltet man über den persönlichen Link aus der E-Mail.
