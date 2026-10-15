<?php
/* Tägliche Aufgaben, gestartet von einem Cronjob bei IONOS (z. B. täglich um 08:00):
   1. Erinnerungs-E-Mail an alle, die morgen einen Online-Termin haben
   2. Name, E-Mail und Telefon aus Online-Terminen entfernen, die älter als REGELN['aufbewahrung'] Monate sind
   Aufruf nur über die Kommandozeile: php termin/aufgaben.php */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/lib/kalender.php';
require_once __DIR__ . '/lib/mail.php';

date_default_timezone_set('Europe/Vienna');

$datei = getenv('TREATBETTER_CONFIG') ?: __DIR__ . '/../../treatbetter-privat/config.php';
if (!is_file($datei)) {
    fwrite(STDERR, "Konfiguration fehlt: $datei\n");
    exit(1);
}
$cfg = require $datei;
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
            $l = leistung($priv['leistung'] ?? '');
            mailErinnerung($cfg, ['beginn' => $beginn, 'leistung' => $l ? leistungsname($l) : '', 'person' => $p['name'],
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
            $l = leistung($priv['leistung'] ?? '');
            terminAendern($cfg, $p['id'], (string) $t['id'], [
                'summary' => 'Online-Termin – ' . ($l ? leistungsname($l) : 'Leistung'),
                'description' => 'Online gebucht. Kundendaten nach ' . REGELN['aufbewahrung'] . ' Monaten automatisch entfernt.',
                'extendedProperties' => ['private' => [
                    'tb' => '1', 'leistung' => $priv['leistung'] ?? '', 'anonym' => '1',
                    'name' => '', 'mail' => '', 'tel' => '', 'tok' => '', 'erinnert' => $priv['erinnert'] ?? '',
                ]],
            ]);
            $geloescht++;
        }
    } catch (Throwable $e) {
        $fehler++;
        fwrite(STDERR, "Aufräumen {$p['id']}: " . $e->getMessage() . "\n");
    }
}

// Alte Fehlerprotokolle kurz halten
$log = $cfg['daten'] . '/fehler.log';
if (is_file($log) && filesize($log) > 200000) file_put_contents($log, substr((string) file_get_contents($log), -100000));

echo date('Y-m-d H:i') . " Erinnerungen: $erinnert, Kundendaten entfernt: $geloescht" . ($fehler ? ", Fehler: $fehler" : '') . "\n";
exit($fehler ? 1 : 0);
