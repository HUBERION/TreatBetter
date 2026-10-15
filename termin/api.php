<?php
/* Schnittstelle der Buchungsseite.
   GET  api.php?a=stamm                                        Leistungen, Team, Regeln
   GET  api.php?a=woche&leistung=…&person=any|…&start=Y-m-d     freie Zeiten für 7 Tage
   POST api.php?a=buchen   (JSON)                               Termin buchen
   GET  api.php?a=termin&t=…                                    gebuchten Termin anzeigen (Storno-Link)
   POST api.php?a=storno   (JSON {t})                           Termin absagen */

declare(strict_types=1);

require_once __DIR__ . '/lib/kalender.php';
require_once __DIR__ . '/lib/mail.php';

date_default_timezone_set('Europe/Vienna');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function antwort(int $status, array $daten): never
{
    http_response_code($status);
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}

function konfiguration(): array
{
    // Die Konfiguration liegt außerhalb des öffentlichen Ordners (siehe termin/LIESMICH.md)
    $datei = getenv('TREATBETTER_CONFIG') ?: __DIR__ . '/../../treatbetter-privat/config.php';
    if (!is_file($datei)) antwort(500, ['fehler' => 'Die Online-Buchung ist noch nicht eingerichtet.']);
    $cfg = require $datei;
    if (!is_dir($cfg['daten'])) mkdir($cfg['daten'], 0700, true);
    return $cfg;
}

function protokoll(array $cfg, string $text): void
{
    // Nur technische Fehler, keine Kundendaten
    @file_put_contents($cfg['daten'] . '/fehler.log', date('c') . ' ' . $text . "\n", FILE_APPEND | LOCK_EX);
}

/* Höchstens $max Anfragen je Stunde und IP-Adresse. Die Adresse wird nur gehasht und mit Tagessalz gespeichert. */
function bremse(array $cfg, string $art, int $max): void
{
    $ordner = $cfg['daten'] . '/bremse';
    if (!is_dir($ordner)) mkdir($ordner, 0700, true);
    $datei = $ordner . '/' . $art . '-' . hash('sha256', $cfg['salz'] . date('Y-m-d') . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $jetzt = time();
    $liste = is_file($datei) ? array_filter(array_map('intval', file($datei, FILE_IGNORE_NEW_LINES)), fn($t) => $t > $jetzt - 3600) : [];
    if (count($liste) >= $max) antwort(429, ['fehler' => 'Zu viele Versuche. Bitte später noch einmal oder ruf uns an: ' . telefonText()]);
    $liste[] = $jetzt;
    file_put_contents($datei, implode("\n", $liste), LOCK_EX);
    // Alte Dateien gelegentlich aufräumen
    if (random_int(1, 50) === 1) {
        foreach (glob($ordner . '/*') ?: [] as $f) if (filemtime($f) < $jetzt - 86400) @unlink($f);
    }
}

function eingabe(): array
{
    $roh = file_get_contents('php://input');
    if (strlen((string) $roh) > 5000) antwort(413, ['fehler' => 'Anfrage zu groß']);
    $d = json_decode((string) $roh, true);
    return is_array($d) ? $d : [];
}

function belegtJePerson(array $cfg, array $personen, int $von, int $bis): array
{
    $out = [];
    foreach ($personen as $p) {
        $out[$p['id']] = array_map(fn($b) => [$b['beginn'], $b['ende']], belegteZeiten($cfg, $p['id'], $von, $bis));
    }
    return $out;
}

function personenFuer(string $wahl): array
{
    if ($wahl === 'any') return TEAM;
    $p = person($wahl);
    if (!$p) antwort(400, ['fehler' => 'Unbekannte Person']);
    return [$p];
}

function stornoHash(string $token): string
{
    return hash('sha256', $token);
}

$cfg = konfiguration();
$a = $_GET['a'] ?? '';
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($a === 'stamm' && $methode === 'GET') {
        antwort(200, [
            'leistungen' => array_map(fn($l) => ['id' => $l['id'], 'gruppe' => $l['gruppe'], 'name' => $l['name'], 'voll' => leistungsname($l)], LEISTUNGEN),
            'team' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'rolle' => $p['rolle']], TEAM),
            'storno' => REGELN['storno'],
            'telefon' => SALON['telefon'],
            'heute' => (new DateTimeImmutable('now', tz()))->format('Y-m-d'),
            'letzterTag' => letzterTag(time()),
            'demo' => $cfg['modus'] === 'demo',
        ]);
    }

    if ($a === 'woche' && $methode === 'GET') {
        $l = leistung((string) ($_GET['leistung'] ?? '')) ?? antwort(400, ['fehler' => 'Unbekannte Leistung']);
        $personen = personenFuer((string) ($_GET['person'] ?? 'any'));
        try { $start = tag((string) ($_GET['start'] ?? '')); } catch (InvalidArgumentException $e) { antwort(400, ['fehler' => 'Ungültiges Datum']); }
        $jetzt = time();
        $belegt = belegtJePerson($cfg, $personen, $start->getTimestamp(), $start->modify('+7 days')->getTimestamp());
        $tage = [];
        for ($i = 0; $i < 7; $i++) {
            $key = $start->modify("+$i days")->format('Y-m-d');
            $o = oeffnung($key);
            $frei = freieZeitenTeam($personen, $key, $l['min'], $belegt, $jetzt);
            $tage[] = [
                'tag' => $key,
                'zu' => !empty($o['zu']),
                'hinweis' => $o['hinweis'] ?? '',
                'zeiten' => array_map(fn($ts, $wer) => ['ts' => $ts, 'uhr' => hhmm($ts), 'wer' => $wer], array_keys($frei), $frei),
            ];
        }
        antwort(200, ['tage' => $tage]);
    }

    if ($a === 'buchen' && $methode === 'POST') {
        bremse($cfg, 'buchen', 5);
        $d = eingabe();
        // Unsichtbares Feld: wird nur von Spam-Programmen ausgefüllt
        if (!empty($d['website'])) antwort(200, ['ok' => true]);
        $l = leistung((string) ($d['leistung'] ?? '')) ?? antwort(400, ['fehler' => 'Bitte eine Leistung wählen.']);
        $personen = personenFuer((string) ($d['person'] ?? 'any'));
        $ts = (int) ($d['ts'] ?? 0);
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($d['name'] ?? '')));
        $mail = trim((string) ($d['mail'] ?? ''));
        $tel = trim((string) ($d['tel'] ?? ''));
        $f = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) $f['name'] = 'Bitte gib deinen Namen an.';
        if (strlen($mail) > 120 || !filter_var($mail, FILTER_VALIDATE_EMAIL)) $f['mail'] = 'Bitte gib eine gültige E-Mail-Adresse an.';
        if (!preg_match('/^[0-9+\/() .-]{6,25}$/', $tel) || preg_match_all('/\d/', $tel) < 6) $f['tel'] = 'Bitte gib eine Telefonnummer an.';
        if (empty($d['agb'])) $f['agb'] = 'Bitte bestätige die Buchungsbedingungen.';
        if ($f) antwort(400, ['fehler' => 'Bitte die markierten Felder prüfen.', 'felder' => $f]);

        $key = (new DateTimeImmutable('@' . $ts))->setTimezone(tz())->format('Y-m-d');
        $von = tag($key)->getTimestamp();
        $bis = tag($key)->modify('+1 day')->getTimestamp();

        // Gleichzeitige Buchungen nacheinander abarbeiten, damit keine Doppelbuchung entsteht
        $sperre = fopen($cfg['daten'] . '/buchen.lock', 'c');
        flock($sperre, LOCK_EX);
        $frei = freieZeitenTeam($personen, $key, $l['min'], belegtJePerson($cfg, $personen, $von, $bis), time());
        $kandidaten = $frei[$ts] ?? [];
        if (!$kandidaten) antwort(409, ['fehler' => 'Diese Uhrzeit ist leider gerade vergeben worden. Bitte wähle eine andere.']);
        // Bei „Beliebig“: wer an dem Tag weniger Buchungen hat, bei Gleichstand Reihenfolge im Team
        usort($kandidaten, fn($x, $y) => buchungenAmTag($cfg, $x, $key) <=> buchungenAmTag($cfg, $y, $key)
            ?: array_search($x, array_column(TEAM, 'id'), true) <=> array_search($y, array_column(TEAM, 'id'), true));
        $p = person($kandidaten[0]);
        $ende = $ts + $l['min'] * 60;
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $voll = leistungsname($l);
        $id = terminEintragen($cfg, $p['id'], [
            'summary' => $name . ' – ' . $voll,
            'description' => "Online gebucht\nLeistung: $voll\nTelefon: $tel\nE-Mail: $mail\n\n"
                . "Absagen: Kundin bzw. Kunden anrufen und den Termin dann hier löschen.",
            'start' => ['dateTime' => rfc3339($ts), 'timeZone' => 'Europe/Vienna'],
            'end' => ['dateTime' => rfc3339($ende), 'timeZone' => 'Europe/Vienna'],
            'transparency' => 'opaque',
            'extendedProperties' => ['private' => [
                'tb' => '1', 'tok' => stornoHash($token), 'leistung' => $l['id'], 'name' => $name, 'mail' => $mail, 'tel' => $tel,
            ]],
        ]);
        flock($sperre, LOCK_UN);

        $buchung = [
            'id' => $id, 'beginn' => $ts, 'ende' => $ende, 'leistung' => $voll, 'person' => $p['name'],
            'name' => $name, 'mail' => $mail, 'tel' => $tel, 'storno_url' => $cfg['basis_url'] . '#storno=' . $token,
        ];
        try {
            mailBestaetigung($cfg, $buchung);
        } catch (Throwable $e) {
            protokoll($cfg, 'Mail fehlgeschlagen: ' . $e->getMessage());
        }
        antwort(200, ['ok' => true, 'termin' => [
            'beginn' => $ts, 'ende' => $ende, 'tag' => $key, 'uhr' => hhmm($ts), 'bis' => hhmm($ende),
            'leistung' => $voll, 'person' => $p['name'], 'mail' => $mail, 'token' => $token,
        ]]);
    }

    if (($a === 'termin' && $methode === 'GET') || ($a === 'storno' && $methode === 'POST')) {
        bremse($cfg, 'storno', 30);
        $token = $a === 'termin' ? (string) ($_GET['t'] ?? '') : (string) (eingabe()['t'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) antwort(404, ['fehler' => 'Termin nicht gefunden.']);
        $treffer = buchungFinden($cfg, stornoHash($token));
        if (!$treffer) antwort(404, ['fehler' => 'Dieser Termin wurde nicht gefunden oder ist bereits abgesagt.']);
        $t = $treffer['termin'];
        [$beginn, $ende] = terminZeit($t);
        $priv = $t['extendedProperties']['private'];
        $l = leistung($priv['leistung'] ?? '');
        $frist = $beginn - REGELN['storno'] * 3600;
        $info = [
            'beginn' => $beginn, 'tag' => (new DateTimeImmutable('@' . $beginn))->setTimezone(tz())->format('Y-m-d'),
            'uhr' => hhmm($beginn), 'bis' => hhmm($ende), 'leistung' => $l ? leistungsname($l) : '',
            'person' => person($treffer['person'])['name'], 'stornierbar' => time() < $frist,
            'frist' => datumLang($frist) . ', ' . hhmm($frist) . ' Uhr', 'vorbei' => $ende < time(),
        ];
        if ($a === 'termin') antwort(200, ['termin' => $info]);
        if (!$info['stornierbar']) antwort(409, ['fehler' => 'Online absagen ist nicht mehr möglich. Bitte ruf uns an: ' . telefonText()]);
        terminLoeschen($cfg, $treffer['person'], (string) $t['id']);
        try {
            mailStorno($cfg, ['id' => (string) $t['id'], 'beginn' => $beginn, 'ende' => $ende, 'leistung' => $info['leistung'], 'person' => $info['person'],
                'name' => $priv['name'] ?? '', 'mail' => $priv['mail'] ?? '']);
        } catch (Throwable $e) {
            protokoll($cfg, 'Mail fehlgeschlagen: ' . $e->getMessage());
        }
        antwort(200, ['ok' => true]);
    }

    antwort(404, ['fehler' => 'Unbekannte Anfrage']);
} catch (KalenderFehler $e) {
    protokoll($cfg, $e->getMessage());
    antwort(503, ['fehler' => 'Der Kalender ist gerade nicht erreichbar. Bitte versuch es später noch einmal oder ruf uns an: ' . telefonText()]);
}
