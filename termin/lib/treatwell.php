<?php
/* Treatwell-Buchungen aus den Benachrichtigungs-E-Mails übernehmen.
   Treatwell gibt Buchungen nicht an Google weiter, schickt aber bei jeder Buchung eine E-Mail
   („Neue Treatwell Buchung (Unsere Ref. T…)“). Diese wird gelesen und als Treatwell-Termin eingetragen.
   Erkennungszeichen jeder Buchung ist die Bestellnummer (T…), gespeichert im Termin als 'tw'.
   Was sich nicht sicher lesen lässt, landet als Hinweis im Dashboard statt geraten zu werden. */

declare(strict_types=1);

require_once __DIR__ . '/kalender.php';
require_once __DIR__ . '/kunden.php';
require_once __DIR__ . '/postfach.php';
require_once __DIR__ . '/mail.php';

/* Leistungsnamen, wie Treatwell sie schreibt (Stand 15.10.2026), zu unseren Leistungen. */
const TREATWELL_NAMEN = [
    'damen - waschen & legen' => 'damen-wl',
    'damen - waschen & föhnen' => 'damen-wf',
    'damen - waschen, schneiden & föhnen' => 'damen-wsf',
    'damen - trockenhaarschnitt' => 'damen-trocken',
    'herren - trockenhaarschnitt' => 'herren-trocken',
    'herren - waschen, schneiden & trocknen' => 'herren-wst',
    'kinder bis 10 jahre - haarschnitt' => 'kinder-10',
    'kinder bis 15 jahren - haarschnitt' => 'kinder-15',
    'teenager bis 16 jahren - waschen, schneiden & föhnen' => 'teen-wsf',
    'studenten - waschen, schneiden & föhnen' => 'stud-wsf',
    'damen - pflege lotion' => 'lotion',
    'damen - haarmaske' => 'maske',
    'pflanzenfarbe - beratungsgespräch' => 'pflanzenfarbe',
    'haarverlängerung - beratung' => 'verlaengerung',
];

const TREATWELL_MONATE = ['jänner' => 1, 'januar' => 1, 'februar' => 2, 'märz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6, 'juli' => 7,
    'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12,
    'january' => 1, 'february' => 2, 'march' => 3, 'may' => 5, 'june' => 6, 'july' => 7, 'october' => 10, 'december' => 12];

function treatwellLeistung(string $name): ?array
{
    $schluessel = mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace(['–', '—'], '-', $name))));
    $schluessel = preg_replace('/\s*-\s*/', ' - ', $schluessel);
    $id = TREATWELL_NAMEN[$schluessel] ?? null;
    return $id ? leistung($id) : null;
}

/* Dauer aus „20 minuten“, „1 stunde 25 minuten + 30 minuten processing“ usw. in Minuten. */
function twDauer(string $s): int
{
    $min = 0;
    preg_match_all('/(\d+)\s*(stunden?|std\.?|h\b|hours?|minuten?|min\.?|minutes?)/iu', $s, $m, PREG_SET_ORDER);
    foreach ($m as $x) $min += preg_match('/^(st|h)/i', $x[2]) ? (int) $x[1] * 60 : (int) $x[1];
    return $min;
}

/* Inhalt einer Treatwell-E-Mail auswerten. Liefert ['art' => neu|storno|aenderung|fremd, …]. */
function treatwellLesen(string $betreff, string $text): array
{
    $flach = trim(preg_replace('/\s+/u', ' ', $text));
    $nr = preg_match('/Bestellnr\.?\s*(T\d{6,})/i', $flach, $m) || preg_match('/Ref\.?\s*(T\d{6,})/i', $betreff . ' ' . $flach, $m) ? $m[1] : '';
    $b = mb_strtolower($betreff);
    $art = str_contains($b, 'storn') || str_contains($b, 'abgesagt') || str_contains($b, 'cancel') ? 'storno'
        : (preg_match('/(geändert|änderung|umgebucht|verschoben|neuer termin)/u', $b) ? 'aenderung'
        : (str_contains($b, 'neue treatwell buchung') || str_contains($b, 'neue buchung') ? 'neu' : 'fremd'));
    $e = ['art' => $art, 'nr' => $nr, 'leistungen' => [], 'beginn' => null, 'person' => null, 'personText' => '',
        'name' => '', 'mail' => '', 'tel' => '', 'bezahlt' => null, 'fehlt' => []];

    // Leistungen mit Dauer zwischen dem letzten „Termin“ vor „Geschäft“ und „Geschäft“
    // („Termin“ kann auch weiter oben im Text vorkommen, z. B. im Absatz zur Bezahlung)
    $g = mb_strpos($flach, 'Geschäft');
    if ($g !== false) {
        $vorher = mb_substr($flach, 0, $g);
        $t = mb_strrpos($vorher, 'Termin');
        if ($t !== false) {
            // z. B. „Herren - Trockenhaarschnitt (20 minuten )“ oder
            // „Paket - Ansatzfarbe … (1 stunde 25 minuten + 30 minuten processing )“ (Einwirkzeit zählt mit)
            preg_match_all('/([^()]+?)\s*\(([^()]*\d[^()]*)\)/u', mb_substr($vorher, $t + 6), $treffer, PREG_SET_ORDER);
            foreach ($treffer as $x) {
                $min = twDauer($x[2]);
                if ($min > 0) $e['leistungen'][] = ['name' => trim($x[1]), 'min' => $min];
            }
        }
    }
    if (preg_match('/Datum\/Zeit\s+(\d{1,2})\.?\s+([A-Za-zÄÖÜäöü]+)\s+(\d{4})\s+(?:at|um)\s+(\d{1,2}):(\d{2})/u', $flach, $m)) {
        $monat = TREATWELL_MONATE[mb_strtolower($m[2])] ?? 0;
        if ($monat && checkdate($monat, (int) $m[1], (int) $m[3])) {
            $e['beginn'] = (new DateTimeImmutable('now', tz()))->setDate((int) $m[3], $monat, (int) $m[1])->setTime((int) $m[4], (int) $m[5])->getTimestamp();
        }
    }
    if (preg_match('/\bbei\s+(.+?)\s+Status\b/u', $flach, $m)) {
        $e['personText'] = trim($m[1]);
        foreach (TEAM as $p) if (mb_strtolower($e['personText']) === mb_strtolower($p['name']) || str_starts_with(mb_strtolower($e['personText']), mb_strtolower($p['name']) . ' ')) $e['person'] = $p['id'];
    }
    if (preg_match('/\bStatus\s+(.+?)\s+(?:In Connect|Kundenname|Gastname)\b/u', $flach, $m)) {
        $s = mb_strtolower($m[1]);
        $e['bezahlt'] = str_contains($s, 'nicht') ? false : (str_contains($s, 'bezahlt') ? true : null);
    }
    // „Kundenname“ bei neuen Buchungen, „Gastname“ bei verschobenen
    if (preg_match('/\b(?:Kundenname|Gastname)\s+(.+?)\s+(?:In Treatwell Connect|Gebucht:|Buchungsdetails)/u', $flach, $m)) {
        // Treatwell hängt „Neu“ an, wenn es für den Salon eine neue Kundin ist
        $e['name'] = trim(preg_replace('/\s+(Neu|Neukunde|Neukundin)$/iu', '', trim($m[1])));
    }
    if (preg_match('/Kunden E-Mail-Adresse:\s*([^\s<>]+@[^\s<>]+)/iu', $flach, $m)) $e['mail'] = mb_substr(trim($m[1], '.;,'), 0, 120);
    if (preg_match('/Kunden Tel\.?:\s*(\+?[\d][\d \/().-]{5,24}?)(?=\s+[A-ZÄÖÜa-z]|$)/u', $flach, $m)) $e['tel'] = trim($m[1]);

    foreach (['nr' => 'Bestellnummer', 'beginn' => 'Datum/Uhrzeit', 'person' => 'Mitarbeiterin', 'name' => 'Kundenname'] as $feld => $text) {
        if (!$e[$feld]) $e['fehlt'][] = $text;
    }
    if (!$e['leistungen']) $e['fehlt'][] = 'Leistung';
    if ($e['mail'] !== '' && !filter_var($e['mail'], FILTER_VALIDATE_EMAIL)) $e['mail'] = '';
    return $e;
}

/* ---------- Zustand: welche E-Mails schon verarbeitet sind, offene Hinweise fürs Dashboard ---------- */

function twZustandDatei(array $cfg): string
{
    return $cfg['daten'] . '/treatwell.json';
}

function twZustand(array $cfg): array
{
    $f = twZustandDatei($cfg);
    $z = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    return $z + ['verarbeitet' => [], 'hinweise' => [], 'letzterAbruf' => 0, 'fehler' => ''];
}

function twZustandSpeichern(array $cfg, array $z): void
{
    // Verarbeitete E-Mails 60 Tage merken, danach vergessen
    $grenze = time() - 60 * 86400;
    $z['verarbeitet'] = array_filter($z['verarbeitet'], fn($v) => ($v['zeit'] ?? 0) > $grenze);
    file_put_contents(twZustandDatei($cfg), json_encode($z, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod(twZustandDatei($cfg), 0600);
}

function twHinweis(array &$z, string $text, string $betreff = '', string $nr = ''): void
{
    $z['hinweise'][] = ['id' => bin2hex(random_bytes(6)), 'zeit' => time(), 'text' => $text, 'betreff' => $betreff, 'nr' => $nr];
}

/* Bestehenden Termin zu einer Treatwell-Bestellnummer finden: ['person' => id, 'termin' => array] oder null. */
function twTerminFinden(array $cfg, string $nr): ?array
{
    $von = time() - 30 * 86400;
    $bis = time() + 400 * 86400;
    foreach (TEAM as $p) {
        $termine = $cfg['modus'] === 'demo'
            ? demoTermine($cfg, $p['id'])
            : googleTermine($cfg, kalenderId($cfg, $p['id']), ['privateExtendedProperty' => 'tw=' . $nr, 'timeMin' => rfc3339($von), 'timeMax' => rfc3339($bis)]);
        foreach ($termine as $t) {
            if (($t['status'] ?? '') !== 'cancelled' && ($t['extendedProperties']['private']['tw'] ?? '') === $nr) return ['person' => $p['id'], 'termin' => $t];
        }
    }
    return null;
}

/* Eine ausgewertete Treatwell-E-Mail anwenden. Liefert einen kurzen Text fürs Protokoll. */
function twAnwenden(array $cfg, array $e, string $betreff, array &$z): string
{
    if ($e['art'] === 'fremd') return 'übersprungen (keine Buchungs-E-Mail)';
    if (!$e['nr']) { twHinweis($z, 'Treatwell-E-Mail ohne Bestellnummer, bitte von Hand prüfen.', $betreff); return 'Hinweis'; }

    if ($e['art'] === 'storno') {
        $t = twTerminFinden($cfg, $e['nr']);
        if (!$t) { twHinweis($z, "Treatwell-Stornierung {$e['nr']}: Termin nicht gefunden, bitte von Hand prüfen.", $betreff, $e['nr']); return 'Hinweis'; }
        terminLoeschen($cfg, $t['person'], (string) $t['termin']['id']);
        karteiBesuchEntfernen($cfg, (string) $t['termin']['id']);
        return 'storniert';
    }

    if ($e['fehlt']) {
        twHinweis($z, "Treatwell-Buchung {$e['nr']} konnte nicht ganz gelesen werden (" . implode(', ', $e['fehlt']) . '). Bitte von Hand eintragen.', $betreff, $e['nr']);
        return 'Hinweis';
    }
    $dauer = array_sum(array_column($e['leistungen'], 'min'));
    $ende = $e['beginn'] + max(5, $dauer) * 60;
    $erste = treatwellLeistung($e['leistungen'][0]['name']);
    $text = count($e['leistungen']) === 1 && $erste ? leistungsname($erste)
        : mb_substr(implode(' + ', array_map(fn($l) => ($x = treatwellLeistung($l['name'])) ? leistungsname($x) : $l['name'], $e['leistungen'])), 0, 60);
    $bezahlt = $e['bezahlt'] === true ? 'Vorab bezahlt' : ($e['bezahlt'] === false ? 'Nicht bezahlt' : 'Bezahlstatus unbekannt');

    $vorhanden = twTerminFinden($cfg, $e['nr']);
    if (!$vorhanden && $e['art'] === 'aenderung') {
        // Buchung von vor dem automatischen Abruf: mit dem neuen Datum eintragen, alten Eintrag von Hand prüfen lassen
        twHinweis($z, "Treatwell-Buchung {$e['nr']} ({$e['name']}) wurde auf " . datumLang($e['beginn']) . ', ' . hhmm($e['beginn'])
            . ' verschoben und neu eingetragen. Falls der alte Termin noch von Hand im Kalender steht, bitte löschen.', $betreff, $e['nr']);
    }
    if ($vorhanden) {
        // Bekannte Buchung: auf den Stand der E-Mail bringen (Zeit, Person)
        $t = $vorhanden['termin'];
        terminUmziehen($cfg, $vorhanden['person'], $e['person'], (string) $t['id']);
        terminAendern($cfg, $e['person'], (string) $t['id'], [
            'start' => ['dateTime' => rfc3339($e['beginn']), 'timeZone' => 'Europe/Vienna'],
            'end' => ['dateTime' => rfc3339($ende), 'timeZone' => 'Europe/Vienna'],
        ]);
        $kunde = (string) ($t['extendedProperties']['private']['kunde'] ?? '');
        if ($kunde !== '') karteiBesuchSetzen($cfg, $kunde, (string) $t['id'], $e['person'], $e['beginn'], $text, $erste['id'] ?? '', 'treatwell');
        return 'aktualisiert';
    }

    // Wurde dieselbe Buchung schon von Hand als Treatwell-Termin eingetragen? Dann nur verknüpfen.
    $tagBeginn = (new DateTimeImmutable('@' . $e['beginn']))->setTimezone(tz())->setTime(0, 0)->getTimestamp();
    $konflikt = [];
    foreach (adminTermine($cfg, $e['person'], $tagBeginn, $tagBeginn + 86400) as $t) {
        [$b, $en, $ganz] = terminZeit($t);
        $priv = $t['extendedProperties']['private'] ?? [];
        if (($priv['quelle'] ?? '') === 'treatwell' && empty($priv['tw']) && abs($b - $e['beginn']) < 600) {
            terminAendern($cfg, $e['person'], (string) $t['id'], ['extendedProperties' => ['private' => array_merge($priv, ['tw' => $e['nr'], 'twbezahlt' => $bezahlt])]]);
            return 'verknüpft (schon von Hand eingetragen)';
        }
        $blockiert = $ganz || ($t['transparency'] ?? 'opaque') !== 'transparent';
        if ($blockiert && $b < $ende && $en > $e['beginn']) $konflikt[] = $ganz ? 'ganztägig' : hhmm($b) . '–' . hhmm($en) . ' ' . ($t['summary'] ?? 'belegt');
    }

    $kundeId = karteZuKontakt(karteiLesen($cfg), $e['mail'], $e['tel']) ?? '';
    $id = terminEintragen($cfg, $e['person'], [
        'summary' => $e['name'] . ' – ' . $text . ' (Treatwell)',
        'description' => "Über Treatwell gebucht\nBestellnr.: {$e['nr']}\n$bezahlt\nLeistung: $text"
            . ($e['tel'] !== '' ? "\nTelefon: {$e['tel']}" : '') . ($e['mail'] !== '' ? "\nE-Mail: {$e['mail']}" : ''),
        'start' => ['dateTime' => rfc3339($e['beginn']), 'timeZone' => 'Europe/Vienna'],
        'end' => ['dateTime' => rfc3339($ende), 'timeZone' => 'Europe/Vienna'],
        'transparency' => 'opaque',
        'extendedProperties' => ['private' => [
            'tb' => '1', 'quelle' => 'treatwell', 'tw' => $e['nr'], 'twbezahlt' => $bezahlt,
            'leistung' => count($e['leistungen']) === 1 && $erste ? $erste['id'] : 'eigene', 'taetigkeit' => count($e['leistungen']) === 1 && $erste ? '' : $text,
            'name' => mb_substr($e['name'], 0, 80), 'mail' => $e['mail'], 'tel' => mb_substr($e['tel'], 0, 25), 'kunde' => $kundeId,
        ]],
    ]);
    if ($kundeId !== '') karteiBesuchSetzen($cfg, $kundeId, $id, $e['person'], $e['beginn'], $text, $erste['id'] ?? '', 'treatwell');
    if ($konflikt) {
        $wann = datumLang($e['beginn']) . ', ' . hhmm($e['beginn']);
        twHinweis($z, "Treatwell-Buchung {$e['nr']} ({$e['name']}, $wann bei " . person($e['person'])['name'] . ') überschneidet sich mit: ' . implode('; ', $konflikt) . '. Bitte klären.', $betreff, $e['nr']);
    }
    return 'eingetragen';
}

/* Postfach abrufen und neue Treatwell-E-Mails verarbeiten. Liefert ['neu' => n, 'protokoll' => [...]]. */
function treatwellAbrufen(array $cfg): array
{
    $pf = $cfg['postfach'] ?? [];
    if (empty($pf['passwort'])) return ['neu' => 0, 'protokoll' => [], 'aus' => true];
    $sperre = fopen($cfg['daten'] . '/treatwell.lock', 'c');
    if (!flock($sperre, LOCK_EX | LOCK_NB)) return ['neu' => 0, 'protokoll' => ['läuft gerade schon']];
    $z = twZustand($cfg);
    $protokoll = [];
    $neu = 0;
    try {
        $imap = new Imap($pf['host'] ?? 'imap.ionos.de', (int) ($pf['port'] ?? 993));
        $imap->anmelden($pf['benutzer'] ?? SALON['mail'], $pf['passwort']);
        $imap->nurLesen($pf['ordner'] ?? 'INBOX');
        foreach ($imap->suchen('treatwell', time() - 14 * 86400) as $uid) {
            $mail = mailText($imap->holen($uid));
            if (isset($z['verarbeitet'][$mail['id']])) continue;
            if (!str_contains(mb_strtolower($mail['von']), 'treatwell')) continue;
            try {
                $e = treatwellLesen($mail['betreff'], $mail['text']);
                $ergebnis = twAnwenden($cfg, $e, $mail['betreff'], $z);
            } catch (KalenderFehler $ex) {
                // Später noch einmal versuchen: nicht als verarbeitet merken
                $protokoll[] = "{$mail['betreff']}: Kalender nicht erreichbar";
                continue;
            }
            $z['verarbeitet'][$mail['id']] = ['zeit' => time(), 'nr' => $e['nr'], 'ergebnis' => $ergebnis];
            $protokoll[] = "{$mail['betreff']}: $ergebnis";
            if ($ergebnis !== 'übersprungen (keine Buchungs-E-Mail)') $neu++;
        }
        $imap->abmelden();
        $z['fehler'] = '';
    } catch (PostfachFehler $ex) {
        $z['fehler'] = $ex->getMessage();
        $protokoll[] = 'Fehler: ' . $ex->getMessage();
    }
    $z['letzterAbruf'] = time();
    twZustandSpeichern($cfg, $z);
    flock($sperre, LOCK_UN);
    return ['neu' => $neu, 'protokoll' => $protokoll];
}
