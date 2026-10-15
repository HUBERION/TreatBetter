<?php
/* Zugriff auf die Buchungskalender „Buchungen Andrea“ und „Buchungen Pamela“.
   Zwei Betriebsarten (Konfiguration 'modus'):
   - google: Google Calendar API über das Dienstkonto (Schlüsseldatei nur auf dem Server)
   - demo:   erfundene Termine aus daten/demo-kalender.json, zum Testen ohne Google

   Gelesen werden nur Beginn, Ende, Status und „Verfügbar/Beschäftigt“, nie Titel oder Inhalte.
   Eigene Buchungen erkennt die Seite an privaten Zusatzfeldern (extendedProperties). */

declare(strict_types=1);

require_once __DIR__ . '/zeit.php';

final class KalenderFehler extends RuntimeException {}

function kalenderId(array $cfg, string $person): string
{
    $id = $cfg['kalender'][$person] ?? '';
    if ($id === '') throw new KalenderFehler('Kein Kalender für ' . $person);
    return $id;
}

/* Belegte Zeiten einer Person: Liste von ['beginn' => ts, 'ende' => ts, 'eigen' => bool]. */
function belegteZeiten(array $cfg, string $person, int $von, int $bis): array
{
    $termine = $cfg['modus'] === 'demo'
        ? demoTermine($cfg, $person)
        : googleTermine($cfg, kalenderId($cfg, $person), ['timeMin' => rfc3339($von), 'timeMax' => rfc3339($bis)]);
    $out = [];
    foreach ($termine as $t) {
        if (($t['status'] ?? '') === 'cancelled') continue;
        [$b, $e, $ganztags] = terminZeit($t);
        if ($e <= $von || $b >= $bis) continue;
        // „Verfügbar“ blockiert nicht, außer bei ganztägigen Terminen (Urlaub ist bei Google standardmäßig „Verfügbar“)
        if (($t['transparency'] ?? 'opaque') === 'transparent' && !$ganztags) continue;
        $out[] = ['beginn' => $b, 'ende' => $e, 'eigen' => isset($t['extendedProperties']['private']['tb'])];
    }
    return $out;
}

/* Beginn, Ende, ganztägig eines Google-Termins. */
function terminZeit(array $t): array
{
    if (isset($t['start']['dateTime'])) {
        return [strtotime($t['start']['dateTime']), strtotime($t['end']['dateTime']), false];
    }
    // Ganztägig: Ende ist bei Google exklusiv (Folgetag 00:00)
    return [tag($t['start']['date'])->getTimestamp(), tag($t['end']['date'])->getTimestamp(), true];
}

/* Neue Buchung eintragen, liefert die Termin-ID. */
function terminEintragen(array $cfg, string $person, array $termin): string
{
    if ($cfg['modus'] === 'demo') {
        $alle = demoLaden($cfg);
        $termin['id'] = bin2hex(random_bytes(8));
        $termin['status'] = 'confirmed';
        $alle[$person][] = $termin;
        demoSpeichern($cfg, $alle);
        return $termin['id'];
    }
    $r = googleAnfrage($cfg, 'POST', '/calendars/' . rawurlencode(kalenderId($cfg, $person)) . '/events', [], $termin);
    return (string) $r['id'];
}

function terminLoeschen(array $cfg, string $person, string $id): void
{
    if ($cfg['modus'] === 'demo') {
        $alle = demoLaden($cfg);
        $alle[$person] = array_values(array_filter($alle[$person] ?? [], fn($t) => $t['id'] !== $id));
        demoSpeichern($cfg, $alle);
        return;
    }
    googleAnfrage($cfg, 'DELETE', '/calendars/' . rawurlencode(kalenderId($cfg, $person)) . '/events/' . rawurlencode($id));
}

/* Eigene Buchung anhand des Storno-Schlüssels (Hash) finden: ['person' => id, 'termin' => array] oder null. */
function buchungFinden(array $cfg, string $hash): ?array
{
    foreach (TEAM as $p) {
        $termine = $cfg['modus'] === 'demo'
            ? demoTermine($cfg, $p['id'])
            : googleTermine($cfg, kalenderId($cfg, $p['id']), ['privateExtendedProperty' => 'tok=' . $hash, 'timeMin' => rfc3339(time() - 86400)]);
        foreach ($termine as $t) {
            if (($t['status'] ?? '') !== 'cancelled' && ($t['extendedProperties']['private']['tok'] ?? '') === $hash) {
                return ['person' => $p['id'], 'termin' => $t];
            }
        }
    }
    return null;
}

/* Eigene Online-Buchungen einer Person, die in [von, bis) beginnen. */
function eigeneBuchungen(array $cfg, string $person, int $von, int $bis): array
{
    $termine = $cfg['modus'] === 'demo'
        ? demoTermine($cfg, $person)
        : googleTermine($cfg, kalenderId($cfg, $person), ['privateExtendedProperty' => 'tb=1', 'timeMin' => rfc3339($von), 'timeMax' => rfc3339($bis)]);
    return array_values(array_filter($termine, function ($t) use ($von, $bis) {
        if (($t['status'] ?? '') === 'cancelled' || !isset($t['extendedProperties']['private']['tb'])) return false;
        $b = terminZeit($t)[0];
        return $b >= $von && $b < $bis;
    }));
}

/* Termin teilweise ändern. Private Zusatzfelder immer vollständig mitgeben,
   zu löschende Felder als leere Zeichenkette. */
function terminAendern(array $cfg, string $person, string $id, array $aenderung): void
{
    if ($cfg['modus'] === 'demo') {
        $alle = demoLaden($cfg);
        foreach ($alle[$person] ?? [] as $i => $t) {
            if ($t['id'] !== $id) continue;
            if (isset($aenderung['extendedProperties']['private'])) {
                $alle[$person][$i]['extendedProperties']['private'] = array_merge(
                    $t['extendedProperties']['private'] ?? [], $aenderung['extendedProperties']['private']);
                unset($aenderung['extendedProperties']);
            }
            $alle[$person][$i] = array_merge($alle[$person][$i], $aenderung);
        }
        demoSpeichern($cfg, $alle);
        return;
    }
    googleAnfrage($cfg, 'PATCH', '/calendars/' . rawurlencode(kalenderId($cfg, $person)) . '/events/' . rawurlencode($id), [], $aenderung);
}

/* Alle Termine eines Buchungskalenders in [von, bis) für das Dashboard, mit Titel.
   Nur für die beiden Buchungskalender des Salons, nie für private Kalender. */
function adminTermine(array $cfg, string $person, int $von, int $bis, ?string $suche = null): array
{
    if ($cfg['modus'] === 'demo') {
        $termine = demoTermine($cfg, $person);
    } else {
        $q = ['timeMin' => rfc3339($von), 'timeMax' => rfc3339($bis)];
        if ($suche !== null) $q['q'] = $suche;
        $termine = googleTermine($cfg, kalenderId($cfg, $person), $q, true);
    }
    return array_values(array_filter($termine, function ($t) use ($von, $bis) {
        if (($t['status'] ?? '') === 'cancelled') return false;
        [$b, $e] = terminZeit($t);
        return $e > $von && $b < $bis;
    }));
}

function terminHolen(array $cfg, string $person, string $id): ?array
{
    if ($cfg['modus'] === 'demo') {
        foreach (demoTermine($cfg, $person) as $t) if ($t['id'] === $id) return $t;
        return null;
    }
    try {
        $t = googleAnfrage($cfg, 'GET', '/calendars/' . rawurlencode(kalenderId($cfg, $person)) . '/events/' . rawurlencode($id),
            ['fields' => 'id,status,start,end,transparency,summary,description,extendedProperties']);
    } catch (KalenderFehler $e) {
        return null;
    }
    return ($t['status'] ?? '') === 'cancelled' ? null : $t;
}

/* Termin in den Kalender einer anderen Person verschieben (Termin-ID bleibt gleich). */
function terminUmziehen(array $cfg, string $von, string $nach, string $id): void
{
    if ($von === $nach) return;
    if ($cfg['modus'] === 'demo') {
        $alle = demoLaden($cfg);
        foreach ($alle[$von] ?? [] as $i => $t) {
            if ($t['id'] !== $id) continue;
            $alle[$nach][] = $t;
            array_splice($alle[$von], $i, 1);
            break;
        }
        demoSpeichern($cfg, $alle);
        return;
    }
    googleAnfrage($cfg, 'POST', '/calendars/' . rawurlencode(kalenderId($cfg, $von)) . '/events/' . rawurlencode($id) . '/move',
        ['destination' => kalenderId($cfg, $nach)]);
}

/* Anzahl eigener Buchungen einer Person an einem Tag (für die Verteilung bei „Beliebig“). */
function buchungenAmTag(array $cfg, string $person, string $key): int
{
    $von = tag($key)->getTimestamp();
    $bis = tag($key)->modify('+1 day')->getTimestamp();
    return count(array_filter(belegteZeiten($cfg, $person, $von, $bis), fn($b) => $b['eigen']));
}

function rfc3339(int $ts): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(tz())->format(DATE_RFC3339);
}

/* ---------- Google Calendar API ---------- */

function googleTermine(array $cfg, string $kalender, array $filter, bool $mitTitel = false): array
{
    $q = $filter + [
        'singleEvents' => 'true',
        'maxResults' => '2500',
        // Für die Buchungsseite werden Titel, Beschreibung, Ort und Teilnehmer gar nicht erst abgefragt.
        // Nur das Dashboard des Salons liest Titel und Beschreibung der eigenen Buchungskalender.
        'fields' => 'items(id,status,start,end,transparency,extendedProperties' . ($mitTitel ? ',summary,description' : '') . '),nextPageToken',
    ];
    $alle = [];
    do {
        $r = googleAnfrage($cfg, 'GET', '/calendars/' . rawurlencode($kalender) . '/events', $q);
        foreach ($r['items'] ?? [] as $t) {
            // Zusatzfelder fremder Termine (z. B. von anderen Apps) verwerfen
            if (!isset($t['extendedProperties']['private']['tb'])) unset($t['extendedProperties']);
            $alle[] = $t;
        }
        $q['pageToken'] = $r['nextPageToken'] ?? null;
    } while ($q['pageToken']);
    return $alle;
}

function googleAnfrage(array $cfg, string $methode, string $pfad, array $query = [], ?array $body = null): array
{
    $url = 'https://www.googleapis.com/calendar/v3' . $pfad . ($query ? '?' . http_build_query(array_filter($query, fn($v) => $v !== null)) : '');
    $kopf = ['Authorization: Bearer ' . googleToken($cfg)];
    if ($body !== null) $kopf[] = 'Content-Type: application/json';
    [$status, $antwort] = http($methode, $url, $kopf, $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE));
    if ($status === 204 || ($methode === 'DELETE' && $status === 410)) return [];
    if ($status < 200 || $status >= 300) throw new KalenderFehler("Google antwortet $status: " . substr($antwort, 0, 300));
    return json_decode($antwort, true) ?: [];
}

/* Zugriffstoken für das Dienstkonto (JWT, RS256), zwischengespeichert bis kurz vor Ablauf. */
function googleToken(array $cfg): string
{
    $cache = $cfg['daten'] . '/google-token.json';
    $c = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
    if ($c && ($c['bis'] ?? 0) > time() + 60) return $c['token'];

    $key = json_decode((string) @file_get_contents($cfg['schluessel']), true);
    if (!$key || empty($key['private_key']) || empty($key['client_email'])) throw new KalenderFehler('Schlüsseldatei fehlt oder ist ungültig');
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $jetzt = time();
    $kopf = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $inhalt = $b64(json_encode([
        'iss' => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar.events',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $jetzt,
        'exp' => $jetzt + 3600,
    ]));
    if (!openssl_sign("$kopf.$inhalt", $sig, $key['private_key'], OPENSSL_ALGO_SHA256)) throw new KalenderFehler('Signieren fehlgeschlagen');
    [$status, $antwort] = http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$kopf.$inhalt." . $b64($sig)]));
    $r = json_decode($antwort, true);
    if ($status !== 200 || empty($r['access_token'])) throw new KalenderFehler("Anmeldung bei Google fehlgeschlagen ($status)");
    @file_put_contents($cache, json_encode(['token' => $r['access_token'], 'bis' => $jetzt + (int) $r['expires_in']]), LOCK_EX);
    @chmod($cache, 0600);
    return $r['access_token'];
}

function http(string $methode, string $url, array $kopf, ?string $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $methode,
        CURLOPT_HTTPHEADER => $kopf,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $antwort = curl_exec($ch);
    if ($antwort === false) throw new KalenderFehler('Keine Verbindung zu Google: ' . curl_error($ch));
    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $antwort];
}

/* ---------- Demo-Kalender (nur zum Testen) ---------- */

function demoLaden(array $cfg): array
{
    $f = $cfg['daten'] . '/demo-kalender.json';
    return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
}

function demoSpeichern(array $cfg, array $alle): void
{
    file_put_contents($cfg['daten'] . '/demo-kalender.json', json_encode($alle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function demoTermine(array $cfg, string $person): array
{
    return demoLaden($cfg)[$person] ?? [];
}
