<?php
/* Treatwell-E-Mails abrufen und als Termine eintragen. Gestartet von einem IONOS-Cronjob (z. B. alle 10 Minuten):
   https://…/termin/treatwell-abruf.php?k=<cron_schluessel>   oder   php termin/treatwell-abruf.php
   Das Dashboard ruft zusätzlich beim Öffnen ab, höchstens alle 2 Minuten. */

declare(strict_types=1);

require_once __DIR__ . '/lib/treatwell.php';

date_default_timezone_set('Europe/Vienna');

$web = PHP_SAPI !== 'cli';
$datei = getenv('TREATBETTER_CONFIG') ?: __DIR__ . '/../../treatbetter-privat/config.php';
if (!is_file($datei)) {
    if ($web) { http_response_code(404); exit; }
    fwrite(STDERR, "Konfiguration fehlt: $datei\n");
    exit(1);
}
$cfg = require $datei;
if ($web) {
    $k = (string) ($_GET['k'] ?? '');
    if (empty($cfg['cron_schluessel']) || strlen($cfg['cron_schluessel']) < 32 || !hash_equals($cfg['cron_schluessel'], $k)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
}

/* Prüfmodus (…&pruefen=1): zeigt für die Treatwell-Mails der letzten 3 Tage nur, was erkannt wird
   (Art, Bestellnummer, was fehlt), getrennt für Text- und HTML-Teil. Ändert nichts, zeigt keine Kundendaten. */
if (isset($_GET['pruefen']) || in_array('--pruefen', $argv ?? [], true)) {
    $pf = $cfg['postfach'] ?? [];
    if (empty($pf['passwort'])) { echo "Treatwell-Abruf ist aus.\n"; exit(0); }
    echo 'Programmstand: ' . (function_exists('treatwellAusMail') ? 'neu (mit HTML-Fassung)' : 'alt') . "\n";
    $imap = new Imap($pf['host'] ?? 'imap.ionos.de', (int) ($pf['port'] ?? 993));
    $imap->anmelden($pf['benutzer'] ?? SALON['mail'], $pf['passwort']);
    $imap->nurLesen($pf['ordner'] ?? 'INBOX');
    foreach ($imap->suchen('treatwell', time() - 3 * 86400) as $uid) {
        $roh = $imap->holen($uid);
        $mail = mailText($roh);
        if (!str_contains(mb_strtolower($mail['von']), 'treatwell')) continue;
        preg_match('/^content-type:\s*([^;\r\n]+)/im', $roh, $ct);
        $teile = preg_match_all('/^content-type:\s*(text\/\w+)/im', $roh, $tt) ? implode(', ', $tt[1]) : '-';
        echo "\n" . date('d.m. H:i', $mail['datum']) . " | {$mail['betreff']} | " . ($ct[1] ?? '?') . " | Teile: $teile\n";
        $nr = treatwellAusMail($mail)['nr'];
        if ($nr !== '') {
            // Steht der Termin noch im Kalender? Einmal über Googles Suche, einmal durch Durchsehen aller Termine
            foreach (['Suche' => true, 'Durchsehen' => false] as $wie => $filter) {
                try {
                    $t = twTerminFinden($cfg, $nr, $filter, false);
                    echo "  Kalender ($wie): " . ($t ? 'gefunden bei ' . $t['person'] . ', ' . date('d.m. H:i', terminZeit($t['termin'])[0]) . ', tb=' . (isset($t['termin']['extendedProperties']['private']['tb']) ? 'ja' : 'nein') : 'nicht gefunden') . "\n";
                } catch (Throwable $ex) {
                    echo "  Kalender ($wie): Fehler " . $ex->getMessage() . "\n";
                }
                if (!$filter) break;
            }
        }
        foreach (['Text' => $mail['text'], 'HTML' => $mail['textHtml'] ?? ''] as $weg => $t) {
            if ($t === '') { echo "  $weg: (leer)\n"; continue; }
            $e = treatwellLesen($mail['betreff'], $t);
            echo "  $weg: Art {$e['art']}, Nr " . ($e['nr'] ?: '–') . ', fehlt: ' . ($e['fehlt'] ? implode(', ', $e['fehlt']) : 'nichts')
                . ', Länge ' . mb_strlen($t) . ', „Bestellreferenz“ ' . (str_contains($t, 'Bestellreferenz') ? 'ja' : 'nein') . "\n";
            // Zeichen nach „Bestellreferenz“ sichtbar machen (unsichtbare Sonderzeichen als U+XXXX)
            if (($p = mb_strpos($t, 'Bestellreferenz')) !== false) {
                $stueck = mb_substr($t, $p + 15, 30);
                echo '    danach: ' . preg_replace_callback('/[^\x21-\x7E]/u', fn($m) => sprintf('[U+%04X]', mb_ord($m[0])), $stueck) . "\n";
            }
        }
    }
    $imap->abmelden();
    // Was steht in den Buchungskalendern? Nur Anzahl, Datum und Treatwell-Nummern, keine Namen
    echo "\nKalender (nächste 60 Tage):\n";
    foreach (TEAM as $p) {
        try {
            $alle = googleTermine($cfg, kalenderId($cfg, $p['id']), ['timeMin' => rfc3339(time() - 86400), 'timeMax' => rfc3339(time() + 60 * 86400)]);
            echo "  {$p['id']}: " . count($alle) . " Termine\n";
            foreach ($alle as $t) {
                $priv = $t['extendedProperties']['private'] ?? [];
                if (($priv['quelle'] ?? '') !== 'treatwell' && empty($priv['tw'])) continue;
                echo '    ' . date('d.m. H:i', terminZeit($t)[0]) . ' Status ' . ($t['status'] ?? '?') . ', quelle ' . ($priv['quelle'] ?? '–') . ', tw ' . ($priv['tw'] ?? '–') . ', tb ' . ($priv['tb'] ?? '–') . "\n";
            }
        } catch (Throwable $ex) {
            echo "  {$p['id']}: Fehler " . $ex->getMessage() . "\n";
        }
    }
    exit(0);
}

$r = treatwellAbrufen($cfg);
if (!empty($r['aus'])) {
    echo "Treatwell-Abruf ist aus (kein Postfach-Passwort in der Konfiguration).\n";
    exit(0);
}
echo date('Y-m-d H:i') . " Treatwell: {$r['neu']} verarbeitet\n" . implode("\n", $r['protokoll']) . "\n";
