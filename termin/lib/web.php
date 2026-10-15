<?php
/* Gemeinsame Hilfsfunktionen für api.php (Kundenseite) und verwaltung.php (Dashboard). */

declare(strict_types=1);

require_once __DIR__ . '/kalender.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/kunden.php';

date_default_timezone_set('Europe/Vienna');

function jsonKopf(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

function antwort(int $status, array $daten): never
{
    http_response_code($status);
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}

function konfiguration(): array
{
    // Die Konfiguration liegt außerhalb des öffentlichen Ordners (siehe doku/buchung.md)
    $datei = getenv('TREATBETTER_CONFIG') ?: __DIR__ . '/../../../treatbetter-privat/config.php';
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
function bremse(array $cfg, string $art, int $max, string $meldung = ''): void
{
    $ordner = $cfg['daten'] . '/bremse';
    if (!is_dir($ordner)) mkdir($ordner, 0700, true);
    $datei = $ordner . '/' . $art . '-' . hash('sha256', $cfg['salz'] . date('Y-m-d') . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $jetzt = time();
    $liste = is_file($datei) ? array_filter(array_map('intval', file($datei, FILE_IGNORE_NEW_LINES)), fn($t) => $t > $jetzt - 3600) : [];
    if (count($liste) >= $max) antwort(429, ['fehler' => $meldung ?: 'Zu viele Versuche. Bitte später noch einmal oder ruf uns an: ' . telefonText()]);
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

function stornoHash(string $token): string
{
    return hash('sha256', $token);
}

function neuerToken(): string
{
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

/* ---------- Einstellungen aus dem Dashboard (daten/einstellungen.json) ---------- */

function einstellungen(array $cfg): array
{
    $f = $cfg['daten'] . '/einstellungen.json';
    $e = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $pausiert = [];
    foreach (TEAM as $p) $pausiert[$p['id']] = !empty($e['pausiert'][$p['id']]);
    return ['pausiert' => $pausiert];
}

function einstellungenSpeichern(array $cfg, array $e): void
{
    file_put_contents($cfg['daten'] . '/einstellungen.json', json_encode($e, JSON_PRETTY_PRINT), LOCK_EX);
}

/* Personen, die gerade online buchbar sind (nicht pausiert). */
function onlineTeam(array $cfg): array
{
    $p = einstellungen($cfg)['pausiert'];
    return array_values(array_filter(TEAM, fn($t) => empty($p[$t['id']])));
}

/* Prüfwerte und Angaben einer Uhrzeit HH:MM. */
function uhrzeit(string $s): ?string
{
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s) ? $s : null;
}

function pruefeKunde(array $d, bool $mailPflicht): array
{
    $name = trim(preg_replace('/\s+/u', ' ', (string) ($d['name'] ?? '')));
    $mail = trim((string) ($d['mail'] ?? ''));
    $tel = trim((string) ($d['tel'] ?? ''));
    $f = [];
    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) $f['name'] = 'Bitte den Namen angeben.';
    if (($mailPflicht || $mail !== '') && (strlen($mail) > 120 || !filter_var($mail, FILTER_VALIDATE_EMAIL))) $f['mail'] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    if (($mailPflicht || $tel !== '') && (!preg_match('/^[0-9+\/() .-]{6,25}$/', $tel) || preg_match_all('/\d/', $tel) < 6)) $f['tel'] = 'Bitte eine gültige Telefonnummer angeben.';
    return [$name, $mail, $tel, $f];
}
