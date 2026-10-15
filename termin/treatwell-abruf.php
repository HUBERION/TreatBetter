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

$r = treatwellAbrufen($cfg);
if (!empty($r['aus'])) {
    echo "Treatwell-Abruf ist aus (kein Postfach-Passwort in der Konfiguration).\n";
    exit(0);
}
echo date('Y-m-d H:i') . " Treatwell: {$r['neu']} verarbeitet\n" . implode("\n", $r['protokoll']) . "\n";
