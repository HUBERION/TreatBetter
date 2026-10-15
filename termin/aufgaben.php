<?php
/* Tägliche Aufgaben, gestartet von einem Cronjob bei IONOS (täglich morgens):
   1. Erinnerungs-E-Mail an alle, die morgen einen Online-Termin haben
   2. Name, E-Mail und Telefon aus Online-Terminen entfernen, die älter als REGELN['aufbewahrung'] Monate sind
   Aufruf: php termin/aufgaben.php  oder  https://…/termin/aufgaben.php?k=<cron_schluessel aus der Konfiguration>
   (Der IONOS-Cronjob-Manager kann nur Adressen aufrufen.) Mehrfaches Aufrufen schadet nicht. */

declare(strict_types=1);

require_once __DIR__ . '/lib/kalender.php';
require_once __DIR__ . '/lib/mail.php';
require_once __DIR__ . '/lib/kunden.php';

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
    // Über das Internet nur mit dem geheimen Schlüssel, sonst wie eine nicht vorhandene Seite
    $k = (string) ($_GET['k'] ?? '');
    if (empty($cfg['cron_schluessel']) || strlen($cfg['cron_schluessel']) < 32 || !hash_equals($cfg['cron_schluessel'], $k)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    if (!defined('STDERR')) define('STDERR', fopen('php://output', 'w'));
}
$jetzt = time();
$heute = new DateTimeImmutable('today', tz());
$fehler = 0;

/* 1. Erinnerungen für morgen */
$von = $heute->modify('+1 day')->getTimestamp();
$bis = $heute->modify('+2 days')->getTimestamp();
$erinnert = 0;
foreach (TEAM as $p) {
    try {
        foreach (eigeneBuchungen($cfg, $p['id'], $von, $bis) as $t) {
            $priv = $t['extendedProperties']['private'];
            if (!empty($priv['erinnert']) || empty($priv['mail'])) continue;
            [$beginn, $ende] = terminZeit($t);
            mailErinnerung($cfg, ['beginn' => $beginn, 'leistung' => leistungAusTermin($priv), 'person' => $p['name'],
                'name' => $priv['name'] ?? '', 'mail' => $priv['mail']]);
            terminAendern($cfg, $p['id'], (string) $t['id'], ['extendedProperties' => ['private' => $priv + ['erinnert' => '1']]]);
            $erinnert++;
        }
    } catch (Throwable $e) {
        $fehler++;
        fwrite(STDERR, "Erinnerung {$p['id']}: " . $e->getMessage() . "\n");
    }
}

/* 2. Alte Kundendaten entfernen (Termine, die vor mehr als 12 Monaten begonnen haben; geprüft werden die 12 Monate davor) */
$grenze = $heute->modify('-' . REGELN['aufbewahrung'] . ' months');
$geloescht = 0;
foreach (TEAM as $p) {
    try {
        foreach (eigeneBuchungen($cfg, $p['id'], $grenze->modify('-12 months')->getTimestamp(), $grenze->getTimestamp()) as $t) {
            $priv = $t['extendedProperties']['private'];
            if (!empty($priv['anonym'])) continue;
            terminAendern($cfg, $p['id'], (string) $t['id'], [
                'summary' => 'Online-Termin – ' . (leistungAusTermin($priv) ?: 'Leistung'),
                'description' => 'Online gebucht. Kundendaten nach ' . REGELN['aufbewahrung'] . ' Monaten automatisch entfernt.',
                'extendedProperties' => ['private' => [
                    'tb' => '1', 'leistung' => $priv['leistung'] ?? '', 'taetigkeit' => $priv['taetigkeit'] ?? '', 'anonym' => '1',
                    'name' => '', 'mail' => '', 'tel' => '', 'tok' => '', 'kunde' => '', 'erinnert' => $priv['erinnert'] ?? '',
                ]],
            ]);
            $geloescht++;
        }
    } catch (Throwable $e) {
        $fehler++;
        fwrite(STDERR, "Aufräumen {$p['id']}: " . $e->getMessage() . "\n");
    }
}

/* 3. Kundenkarten löschen, die seit 3 Jahren keinen Besuch hatten */
$karten = 0;
try {
    $karten = karteiAufraeumen($cfg, $jetzt);
} catch (Throwable $e) {
    $fehler++;
    fwrite(STDERR, "Kartei: " . $e->getMessage() . "
");
}

// Alte Fehlerprotokolle kurz halten
$log = $cfg['daten'] . '/fehler.log';
if (is_file($log) && filesize($log) > 200000) file_put_contents($log, substr((string) file_get_contents($log), -100000));

$zeile = date('Y-m-d H:i') . " Erinnerungen: $erinnert, Kundendaten entfernt: $geloescht, Karten gelöscht: $karten" . ($fehler ? ", Fehler: $fehler" : '');
@file_put_contents($cfg['daten'] . '/aufgaben.log', $zeile . "\n", FILE_APPEND | LOCK_EX);
if ($web && $fehler) http_response_code(500);
echo $zeile . "\n";
exit($fehler ? 1 : 0);
