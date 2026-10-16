<?php
/* E-Mails an Kund:innen und an den Salon. Im Demo-Modus landen sie als Dateien in daten/mails/. */

declare(strict_types=1);

require_once __DIR__ . '/salon.php';
require_once __DIR__ . '/zeit.php';

function mailSenden(array $cfg, string $an, string $betreff, string $text, ?string $ics = null): bool
{
    $absender = SALON['mail'];
    $grenze = 'tb-' . bin2hex(random_bytes(8));
    $kopf = [
        'From: ' . mb_encode_mimeheader(SALON['name'], 'UTF-8') . ' <' . $absender . '>',
        'Reply-To: ' . $absender,
        'MIME-Version: 1.0',
    ];
    if ($ics === null) {
        $kopf[] = 'Content-Type: text/plain; charset=UTF-8';
        $kopf[] = 'Content-Transfer-Encoding: 8bit';
        $inhalt = $text;
    } else {
        $kopf[] = 'Content-Type: multipart/mixed; boundary="' . $grenze . '"';
        $inhalt = "--$grenze\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$text\r\n"
            . "--$grenze\r\nContent-Type: text/calendar; charset=UTF-8; method=PUBLISH; name=\"termin.ics\"\r\n"
            . "Content-Disposition: attachment; filename=\"termin.ics\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($ics)) . "--$grenze--\r\n";
    }
    $betreffKodiert = mb_encode_mimeheader($betreff, 'UTF-8');

    if ($cfg['modus'] === 'demo' || empty($cfg['mail_aktiv'])) {
        $ordner = $cfg['daten'] . '/mails';
        if (!is_dir($ordner)) mkdir($ordner, 0700, true);
        file_put_contents($ordner . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml',
            "To: $an\r\nSubject: $betreffKodiert\r\n" . implode("\r\n", $kopf) . "\r\n\r\n" . $inhalt);
        return true;
    }
    return mail($an, $betreffKodiert, $inhalt, implode("\r\n", $kopf), '-f' . $absender);
}

function datumLang(int $ts): string
{
    $d = (new DateTimeImmutable('@' . $ts))->setTimezone(tz());
    $tage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    $monate = ['', 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    return $tage[(int) $d->format('w')] . ', ' . $d->format('j') . '. ' . $monate[(int) $d->format('n')] . ' ' . $d->format('Y');
}

function icsDatei(string $uid, int $beginn, int $ende, string $titel, bool $abgesagt = false, ?string $an = null, int $folge = 0): string
{
    $f = fn(int $ts) => gmdate('Ymd\THis\Z', $ts);
    $esc = fn(string $s) => addcslashes($s, ",;\\");
    // Gleiche UID bei Buchung und Absage, damit Kalender-Apps die Absage dem Termin zuordnen können
    $zeilen = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Friseur Andrea Zechmeister//Online-Buchung//DE',
        'METHOD:' . ($abgesagt ? 'CANCEL' : 'PUBLISH'),
        'BEGIN:VEVENT',
        'UID:' . $uid . '@andreazechmeister.at',
        // Höhere Folgenummer = neuere Fassung desselben Termins (verschoben bzw. abgesagt)
        'SEQUENCE:' . ($abgesagt ? max($folge, 0) + 1 : $folge),
        'DTSTAMP:' . $f(time()),
        'DTSTART:' . $f($beginn),
        'DTEND:' . $f($ende),
        'SUMMARY:' . $esc(($abgesagt ? 'Abgesagt: ' : '') . $titel),
        'LOCATION:' . $esc(SALON['name'] . ', ' . SALON['strasse'] . ', ' . SALON['ort']),
        'ORGANIZER;CN=' . $esc(SALON['name']) . ':mailto:' . SALON['mail'],
    ];
    if ($an !== null) $zeilen[] = 'ATTENDEE;ROLE=REQ-PARTICIPANT:mailto:' . $an;
    $zeilen[] = 'STATUS:' . ($abgesagt ? 'CANCELLED' : 'CONFIRMED');
    return implode("\r\n", array_merge($zeilen, ['END:VEVENT', 'END:VCALENDAR', '']));
}

function telefonText(): string
{
    return SALON['telefon'][0][0] . ' oder ' . SALON['telefon'][1][0];
}

function mailBestaetigung(array $cfg, array $b): void
{
    $frist = $b['beginn'] - REGELN['storno'] * 3600;
    $text = "Hallo {$b['name']},\n\n"
        . "danke für deine Buchung! Dein Termin:\n\n"
        . "  Wann:   " . datumLang($b['beginn']) . ', ' . hhmm($b['beginn']) . " Uhr\n"
        . "  Was:    {$b['leistung']}\n"
        . "  Bei:    {$b['person']}\n"
        . "  Wo:     " . SALON['name'] . ', ' . SALON['strasse'] . ', ' . SALON['ort'] . "\n\n"
        . "Bezahlt wird im Salon, bar oder mit Karte.\n"
        . "Parkplätze gibt es im Innenhof, die Einfahrt ist über die Rosentaler Straße.\n\n"
        . "Termin verschieben oder absagen (kostenlos bis " . datumLang($frist) . ', ' . hhmm($frist) . " Uhr):\n"
        . "{$b['storno_url']}\n\n"
        . "Danach bitte telefonisch melden: " . telefonText() . "\n\n"
        . "Bis bald!\n" . SALON['name'] . "\n";
    mailSenden($cfg, $b['mail'], 'Dein Termin am ' . datumLang($b['beginn']) . ' um ' . hhmm($b['beginn']) . ' Uhr', $text,
        icsDatei($b['id'], $b['beginn'], $b['ende'], $b['leistung'] . ' – ' . SALON['name'], false, $b['mail']));

    if (!empty($cfg['mail_an_salon'])) {
        mailSenden($cfg, SALON['mail'], 'Neue Online-Buchung: ' . datumLang($b['beginn']) . ' ' . hhmm($b['beginn']) . ' ' . $b['person'],
            "Neue Online-Buchung\n\n"
            . "Wann:     " . datumLang($b['beginn']) . ', ' . hhmm($b['beginn']) . '–' . hhmm($b['ende']) . " Uhr\n"
            . "Bei:      {$b['person']}\n"
            . "Leistung: {$b['leistung']}\n"
            . "Name:     {$b['name']}\n"
            . "Telefon:  {$b['tel']}\n"
            . "E-Mail:   {$b['mail']}\n\n"
            . "Der Termin steht bereits im Kalender „Buchungen {$b['person']}“.\n");
    }
}

/* Termin wurde verschoben: vom Salon (Dashboard) oder von der Kundin selbst über den Link ($vonKundin). */
function mailVerschoben(array $cfg, array $b, bool $vonKundin = false): void
{
    $text = "Hallo {$b['name']},\n\n"
        . ($vonKundin ? "du hast deinen Termin verschoben. Dein neuer Termin:\n\n" : "dein Termin hat sich geändert. So sieht er jetzt aus:\n\n")
        . "  Wann:   " . datumLang($b['beginn']) . ', ' . hhmm($b['beginn']) . " Uhr\n"
        . "  Was:    {$b['leistung']}\n"
        . "  Bei:    {$b['person']}\n"
        . "  Wo:     " . SALON['name'] . ', ' . SALON['strasse'] . ', ' . SALON['ort'] . "\n\n"
        . "Im Anhang ist der neue Kalendereintrag. Viele Kalender-Apps ändern den gespeicherten Termin damit von selbst.\n\n"
        . (!empty($b['storno_url'])
            ? "Termin verschieben oder absagen (kostenlos bis " . datumLang($b['beginn'] - REGELN['storno'] * 3600) . ', ' . hhmm($b['beginn'] - REGELN['storno'] * 3600) . " Uhr):\n{$b['storno_url']}\n\n"
            : '')
        . ($vonKundin ? "Fragen? Ruf uns an: " : "Passt dir der neue Termin nicht? Ruf uns an: ") . telefonText() . "\n\n"
        . "Liebe Grüße\n" . SALON['name'] . "\n";
    if (($b['mail'] ?? '') !== '') {
        mailSenden($cfg, $b['mail'], ($vonKundin ? 'Termin verschoben: ' : 'Dein Termin wurde geändert: ') . datumLang($b['beginn']) . ' um ' . hhmm($b['beginn']) . ' Uhr', $text,
            icsDatei($b['id'], $b['beginn'], $b['ende'], $b['leistung'] . ' – ' . SALON['name'], false, $b['mail'], (int) ($b['folge'] ?? 0)));
    }

    if ($vonKundin && !empty($cfg['mail_an_salon'])) {
        mailSenden($cfg, SALON['mail'], 'Online-Buchung verschoben: ' . datumLang($b['beginn']) . ' ' . hhmm($b['beginn']) . ' ' . $b['person'],
            "Eine Online-Buchung wurde von der Kundin bzw. vom Kunden verschoben.\n\n"
            . "Neu:      " . datumLang($b['beginn']) . ', ' . hhmm($b['beginn']) . '–' . hhmm($b['ende']) . " Uhr bei {$b['person']}\n"
            . "Bisher:   " . ($b['alt'] ?? '') . "\n"
            . "Leistung: {$b['leistung']}\n"
            . "Name:     {$b['name']}\n"
            . "Telefon:  " . ($b['tel'] ?? '') . "\n\n"
            . "Der Kalender ist schon aktualisiert, die alte Zeit ist wieder frei.\n");
    }
}

function mailErinnerung(array $cfg, array $b): void
{
    mailSenden($cfg, $b['mail'], 'Erinnerung: dein Termin morgen um ' . hhmm($b['beginn']) . ' Uhr',
        "Hallo {$b['name']},\n\n"
        . "wir freuen uns auf dich! Zur Erinnerung:\n\n"
        . "  Wann:   " . datumLang($b['beginn']) . ', ' . hhmm($b['beginn']) . " Uhr\n"
        . "  Was:    {$b['leistung']}\n"
        . "  Bei:    {$b['person']}\n"
        . "  Wo:     " . SALON['name'] . ', ' . SALON['strasse'] . ', ' . SALON['ort'] . "\n\n"
        . "Parkplätze gibt es im Innenhof, die Einfahrt ist über die Rosentaler Straße.\n"
        . "Bezahlt wird im Salon, bar oder mit Karte.\n\n"
        . "Falls du nicht kommen kannst, ruf uns bitte an: " . telefonText() . "\n\n"
        . "Bis morgen!\n" . SALON['name'] . "\n");
}

/* Absage-E-Mail. $vomSalon: der Salon hat abgesagt (Dashboard), sonst die Kundin selbst über den Link. */
function mailStorno(array $cfg, array $b, bool $vomSalon = false): void
{
    $wann = datumLang($b['beginn']) . ' um ' . hhmm($b['beginn']) . ' Uhr';
    $text = $vomSalon
        ? "Hallo {$b['name']},\n\n"
            . "leider müssen wir deinen Termin am $wann ({$b['leistung']}) absagen. Das tut uns leid!\n\n"
            . "Ruf uns gerne an, dann finden wir einen neuen Termin: " . telefonText() . "\n"
            . "Oder buch dir online einen neuen Termin: {$cfg['basis_url']}\n\n"
        : "Hallo {$b['name']},\n\n"
            . "dein Termin am $wann ({$b['leistung']}) ist abgesagt.\n\n"
            . "Einen neuen Termin kannst du jederzeit online buchen: {$cfg['basis_url']}\n\n";
    $text .= "Falls du den Termin in deinem Kalender gespeichert hast: Im Anhang ist die Absage dazu. "
        . "Viele Kalender-Apps entfernen den Termin damit von selbst, sonst bitte einfach löschen.\n\n"
        . "Liebe Grüße\n" . SALON['name'] . "\n";
    mailSenden($cfg, $b['mail'], 'Termin abgesagt: ' . $wann, $text,
        icsDatei($b['id'], $b['beginn'], $b['ende'], $b['leistung'] . ' – ' . SALON['name'], true, $b['mail'], (int) ($b['folge'] ?? 0)));
    if (!$vomSalon && !empty($cfg['mail_an_salon'])) {
        mailSenden($cfg, SALON['mail'], 'Online-Buchung abgesagt: ' . datumLang($b['beginn']) . ' ' . hhmm($b['beginn']) . ' ' . $b['person'],
            "Eine Online-Buchung wurde von der Kundin bzw. vom Kunden abgesagt.\n\n"
            . "Wann:     " . datumLang($b['beginn']) . ', ' . hhmm($b['beginn']) . " Uhr\n"
            . "Bei:      {$b['person']}\n"
            . "Leistung: {$b['leistung']}\n"
            . "Name:     {$b['name']}\n\n"
            . "Der Termin wurde aus dem Kalender gelöscht, die Zeit ist wieder frei.\n");
    }
}
