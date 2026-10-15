<?php
/* Schnittstelle des Dashboards für den Salon (verwaltung.html). Alles außer login/status nur angemeldet.
   POST verwaltung.php?a=login    {passwort}
   POST verwaltung.php?a=logout
   GET  verwaltung.php?a=status
   GET  verwaltung.php?a=daten&von=Y-m-d&tage=1..7     Termine, Öffnungszeiten, Einstellungen
   POST verwaltung.php?a=eintragen {art: termin|block, …}
   POST verwaltung.php?a=verschieben {person, id, nach, tag, uhr, mail}
   POST verwaltung.php?a=absagen {person, id, mail}
   GET  verwaltung.php?a=suche&q=…
   POST verwaltung.php?a=pause {person, pausiert} */

declare(strict_types=1);

require_once __DIR__ . '/lib/web.php';
require_once __DIR__ . '/lib/treatwell.php';

jsonKopf();
header('X-Frame-Options: DENY');

const SITZUNG_COOKIE = 'tb_verwaltung';
const SITZUNG_TAGE = 30;

/* Anmeldung als signiertes Cookie: Ablaufzeit + HMAC. Kein Server-Speicher nötig.
   Alle Sitzungen beenden: 'verwaltung_geheimnis' in der Konfiguration ändern. */
function sitzungWert(array $cfg, int $bis): string
{
    return $bis . '.' . hash_hmac('sha256', 'verwaltung|' . $bis, $cfg['verwaltung_geheimnis']);
}

function angemeldet(array $cfg): bool
{
    $c = (string) ($_COOKIE[SITZUNG_COOKIE] ?? '');
    if (!preg_match('/^(\d{10})\.([0-9a-f]{64})$/', $c, $m)) return false;
    return (int) $m[1] > time() && hash_equals(sitzungWert($cfg, (int) $m[1]), $c);
}

function cookieSetzen(string $wert, int $bis): void
{
    setcookie(SITZUNG_COOKIE, $wert, [
        'expires' => $bis,
        'path' => dirname($_SERVER['SCRIPT_NAME'] ?? '/termin/verwaltung.php') . '/',
        'secure' => ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function zeitpunkt(string $tag, string $uhr): int
{
    if (!uhrzeit($uhr)) antwort(400, ['fehler' => 'Bitte eine gültige Uhrzeit angeben.']);
    try {
        return um($tag, $uhr);
    } catch (InvalidArgumentException $e) {
        antwort(400, ['fehler' => 'Bitte ein gültiges Datum angeben.']);
    }
}

function personPruefen(string $id): array
{
    return person($id) ?? antwort(400, ['fehler' => 'Bitte Andrea oder Pamela wählen.']);
}

/* Stand des Treatwell-Abrufs fürs Dashboard. */
function twStatus(array $cfg): array
{
    $z = twZustand($cfg);
    return ['aktiv' => !empty($cfg['postfach']['passwort']), 'letzterAbruf' => $z['letzterAbruf'], 'fehler' => $z['fehler'], 'hinweise' => $z['hinweise']];
}

/* Termin für das Dashboard aufbereiten. */
function adminSicht(array $t, string $person): array
{
    [$b, $e, $ganztags] = terminZeit($t);
    $priv = $t['extendedProperties']['private'] ?? [];
    $quelle = $priv['quelle'] ?? '';
    $art = isset($priv['tb']) ? (in_array($quelle, ['telefon', 'treatwell'], true) ? $quelle : 'online')
        : (isset($priv['tbb']) ? 'block' : 'kalender');
    $l = leistung($priv['leistung'] ?? '');
    return [
        'id' => (string) $t['id'], 'person' => $person, 'art' => $art,
        'beginn' => $b, 'ende' => $e, 'ganztags' => $ganztags,
        'tag' => (new DateTimeImmutable('@' . $b))->setTimezone(tz())->format('Y-m-d'),
        'uhr' => $ganztags ? '' : hhmm($b), 'bis' => $ganztags ? '' : hhmm($e),
        'titel' => (string) ($t['summary'] ?? ''),
        'notiz' => $art === 'block' || $art === 'kalender' ? (string) ($t['description'] ?? '') : '',
        'name' => (string) ($priv['name'] ?? ''), 'tel' => (string) ($priv['tel'] ?? ''), 'mail' => (string) ($priv['mail'] ?? ''),
        'leistung' => leistungAusTermin($priv), 'leistungId' => $l['id'] ?? '',
        'anonym' => !empty($priv['anonym']), 'erinnert' => !empty($priv['erinnert']), 'kunde' => (string) ($priv['kunde'] ?? ''),
        'twNr' => (string) ($priv['tw'] ?? ''), 'twBezahlt' => (string) ($priv['twbezahlt'] ?? ''),
        'blockiert' => $ganztags || ($t['transparency'] ?? 'opaque') !== 'transparent',
    ];
}

/* Überschneidungen mit anderen Terminen derselben Person (ohne Puffer). */
function konflikte(array $cfg, string $person, int $beginn, int $ende, ?string $ohneId = null): array
{
    $out = [];
    $tagBeginn = (new DateTimeImmutable('@' . $beginn))->setTimezone(tz())->setTime(0, 0);
    foreach (adminTermine($cfg, $person, $tagBeginn->getTimestamp(), $tagBeginn->modify('+1 day')->getTimestamp()) as $t) {
        if ($t['id'] === $ohneId) continue;
        $s = adminSicht($t, $person);
        if (!$s['blockiert']) continue;
        if ($s['beginn'] < $ende && $s['ende'] > $beginn) {
            $out[] = ($s['ganztags'] ? 'ganztägig' : $s['uhr'] . '–' . $s['bis']) . ' ' . ($s['name'] ?: $s['titel'] ?: 'belegt');
        }
    }
    return $out;
}

$cfg = konfiguration();
if (empty($cfg['verwaltung_passwort']) || empty($cfg['verwaltung_geheimnis']) || strlen($cfg['verwaltung_geheimnis']) < 32) {
    antwort(503, ['fehler' => 'Das Dashboard ist noch nicht eingerichtet (Passwort fehlt in der Konfiguration).']);
}
$a = $_GET['a'] ?? '';
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Schreibende Anfragen nur aus dem Dashboard selbst (eigener Kopf, den fremde Seiten nicht setzen können)
if ($methode === 'POST' && ($_SERVER['HTTP_X_VERWALTUNG'] ?? '') !== '1') antwort(403, ['fehler' => 'Nicht erlaubt']);

try {
    if ($a === 'status' && $methode === 'GET') {
        antwort(200, ['angemeldet' => angemeldet($cfg), 'demo' => $cfg['modus'] === 'demo']);
    }

    if ($a === 'login' && $methode === 'POST') {
        bremse($cfg, 'login', 10, 'Zu viele Anmeldeversuche. Bitte in einer Stunde noch einmal.');
        $pw = (string) (eingabe()['passwort'] ?? '');
        if (!password_verify($pw, $cfg['verwaltung_passwort'])) {
            usleep(500000);
            antwort(401, ['fehler' => 'Das Passwort stimmt nicht.']);
        }
        $bis = time() + SITZUNG_TAGE * 86400;
        cookieSetzen(sitzungWert($cfg, $bis), $bis);
        antwort(200, ['ok' => true]);
    }

    if ($a === 'logout' && $methode === 'POST') {
        cookieSetzen('', time() - 3600);
        antwort(200, ['ok' => true]);
    }

    if (!angemeldet($cfg)) antwort(401, ['fehler' => 'Bitte anmelden.']);

    if ($a === 'daten' && $methode === 'GET') {
        try { $start = tag((string) ($_GET['von'] ?? '')); } catch (InvalidArgumentException $e) { antwort(400, ['fehler' => 'Ungültiges Datum']); }
        $anzahl = max(1, min(7, (int) ($_GET['tage'] ?? 1)));
        $von = $start->getTimestamp();
        $bis = $start->modify("+$anzahl days")->getTimestamp();
        $termine = [];
        foreach (TEAM as $p) {
            foreach (adminTermine($cfg, $p['id'], $von, $bis) as $t) $termine[] = adminSicht($t, $p['id']);
        }
        usort($termine, fn($x, $y) => $x['beginn'] <=> $y['beginn']);
        $tage = [];
        for ($i = 0; $i < $anzahl; $i++) {
            $key = $start->modify("+$i days")->format('Y-m-d');
            $tage[] = ['tag' => $key] + oeffnung($key);
        }
        antwort(200, [
            'tage' => $tage, 'termine' => $termine,
            'team' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'bis' => $p['bis']], TEAM),
            'leistungen' => array_map(fn($l) => ['id' => $l['id'], 'voll' => leistungsname($l), 'min' => $l['min']], LEISTUNGEN),
            'pausiert' => einstellungen($cfg)['pausiert'],
            'heute' => (new DateTimeImmutable('now', tz()))->format('Y-m-d'),
            'jetzt' => time(),
            'demo' => $cfg['modus'] === 'demo',
            'treatwell' => twStatus($cfg),
        ]);
    }

    if ($a === 'eintragen' && $methode === 'POST') {
        $d = eingabe();
        $p = personPruefen((string) ($d['person'] ?? ''));
        $tag = (string) ($d['tag'] ?? '');
        $trotzdem = !empty($d['trotzdem']);

        if (($d['art'] ?? '') === 'termin') {
            // Leistung aus der Liste oder „eigene“ Tätigkeit mit selbst gewählter Bezeichnung
            $eigene = ($d['leistung'] ?? '') === 'eigene';
            $l = $eigene ? null : leistung((string) ($d['leistung'] ?? ''));
            [$name, $mail, $tel, $f] = pruefeKunde($d, false);
            $taetigkeit = trim(preg_replace('/\s+/u', ' ', (string) ($d['taetigkeit'] ?? '')));
            if (!$eigene && !$l) $f['leistung'] = 'Bitte eine Leistung wählen.';
            if ($eigene && (mb_strlen($taetigkeit) < 2 || mb_strlen($taetigkeit) > 60)) $f['taetigkeit'] = 'Bitte angeben, was gemacht wird.';
            // Dauer: Vorgabe der Leistung oder selbst eingetragen (5 bis 600 Minuten)
            $dauer = isset($d['dauer']) && $d['dauer'] !== '' && $d['dauer'] !== null ? (int) $d['dauer'] : ($l['min'] ?? 0);
            if ($dauer < 5 || $dauer > 600) $f['dauer'] = 'Bitte eine Dauer zwischen 5 und 600 Minuten angeben.';
            if ($f) antwort(400, ['fehler' => 'Bitte die markierten Felder prüfen.', 'felder' => $f]);
            $beginn = zeitpunkt($tag, (string) ($d['uhr'] ?? ''));
            $ende = $beginn + $dauer * 60;
            if (!$trotzdem && ($k = konflikte($cfg, $p['id'], $beginn, $ende))) antwort(409, ['konflikt' => $k, 'fehler' => 'Zu dieser Zeit ist schon etwas eingetragen.']);
            $voll = $eigene ? $taetigkeit : leistungsname($l);
            // Kundenkarte: bestehende wählen oder auf Wunsch neu anlegen
            $kundeId = (string) ($d['kundeId'] ?? '');
            if ($kundeId !== '' && karteIndex(karteiLesen($cfg), $kundeId) === null) $kundeId = '';
            if ($kundeId === '' && !empty($d['kartei'])) {
                $karte = neueKarte($name, $tel, $mail, '');
                karteiAendern($cfg, function (array &$liste) use ($karte) { $liste[] = $karte; });
                $kundeId = $karte['id'];
            }
            // Treatwell-Termine: Treatwell bestätigt und erinnert selbst, deshalb keine E-Mails und kein Storno-Link von uns
            $quelle = ($d['quelle'] ?? '') === 'treatwell' ? 'treatwell' : 'telefon';
            $token = $mail !== '' && $quelle === 'telefon' ? neuerToken() : '';
            $id = terminEintragen($cfg, $p['id'], [
                'summary' => $name . ' – ' . $voll . ($quelle === 'treatwell' ? ' (Treatwell)' : ''),
                'description' => ($quelle === 'treatwell' ? 'Über Treatwell gebucht' : 'Telefonisch eingetragen') . "\nLeistung: $voll" . ($tel !== '' ? "\nTelefon: $tel" : '') . ($mail !== '' ? "\nE-Mail: $mail" : ''),
                'start' => ['dateTime' => rfc3339($beginn), 'timeZone' => 'Europe/Vienna'],
                'end' => ['dateTime' => rfc3339($ende), 'timeZone' => 'Europe/Vienna'],
                'transparency' => 'opaque',
                'extendedProperties' => ['private' => [
                    'tb' => '1', 'quelle' => $quelle, 'tok' => $token !== '' ? stornoHash($token) : '',
                    'leistung' => $eigene ? 'eigene' : $l['id'], 'taetigkeit' => $eigene ? $taetigkeit : '',
                    'name' => $name, 'mail' => $mail, 'tel' => $tel, 'kunde' => $kundeId,
                ]],
            ]);
            if ($kundeId !== '') karteiBesuchSetzen($cfg, $kundeId, $id, $p['id'], $beginn, $voll, $eigene ? '' : $l['id'], $quelle);
            $gemailt = false;
            if ($mail !== '' && !empty($d['mailSenden']) && $quelle === 'telefon') {
                try {
                    mailBestaetigung(['mail_an_salon' => false] + $cfg, [
                        'id' => $id, 'beginn' => $beginn, 'ende' => $ende, 'leistung' => $voll, 'person' => $p['name'],
                        'name' => $name, 'mail' => $mail, 'tel' => $tel, 'storno_url' => $cfg['basis_url'] . '#storno=' . $token,
                    ]);
                    $gemailt = true;
                } catch (Throwable $e) {
                    protokoll($cfg, 'Mail fehlgeschlagen: ' . $e->getMessage());
                }
            }
            antwort(200, ['ok' => true, 'id' => $id, 'gemailt' => $gemailt]);
        }

        if (($d['art'] ?? '') === 'block') {
            $grund = trim((string) ($d['grund'] ?? ''));
            if ($grund === '' || mb_strlen($grund) > 60) antwort(400, ['fehler' => 'Bitte angeben, was eingetragen wird (z. B. Pause).']);
            $notiz = mb_substr(trim((string) ($d['notiz'] ?? '')), 0, 300);
            $ereignis = [
                'summary' => $grund, 'description' => $notiz, 'transparency' => 'opaque',
                'extendedProperties' => ['private' => ['tbb' => '1']],
            ];
            if (!empty($d['ganztags'])) {
                $bisTag = (string) ($d['bisTag'] ?? '') ?: $tag;
                try { $t1 = tag($tag); $t2 = tag($bisTag); } catch (InvalidArgumentException $e) { antwort(400, ['fehler' => 'Bitte ein gültiges Datum angeben.']); }
                if ($t2 < $t1) antwort(400, ['fehler' => 'Das Ende liegt vor dem Beginn.']);
                if ($t1->diff($t2)->days > 60) antwort(400, ['fehler' => 'Bitte höchstens 60 Tage auf einmal eintragen.']);
                $ereignis['start'] = ['date' => $t1->format('Y-m-d')];
                $ereignis['end'] = ['date' => $t2->modify('+1 day')->format('Y-m-d')];
            } else {
                $beginn = zeitpunkt($tag, (string) ($d['uhr'] ?? ''));
                $ende = zeitpunkt($tag, (string) ($d['bis'] ?? ''));
                if ($ende <= $beginn) antwort(400, ['fehler' => 'Das Ende muss nach dem Beginn liegen.']);
                $ereignis['start'] = ['dateTime' => rfc3339($beginn), 'timeZone' => 'Europe/Vienna'];
                $ereignis['end'] = ['dateTime' => rfc3339($ende), 'timeZone' => 'Europe/Vienna'];
            }
            $id = terminEintragen($cfg, $p['id'], $ereignis);
            antwort(200, ['ok' => true, 'id' => $id]);
        }
        antwort(400, ['fehler' => 'Unbekannte Art']);
    }

    if ($a === 'verschieben' && $methode === 'POST') {
        $d = eingabe();
        $p = personPruefen((string) ($d['person'] ?? ''));
        $nach = personPruefen((string) ($d['nach'] ?? $p['id']));
        $t = terminHolen($cfg, $p['id'], (string) ($d['id'] ?? '')) ?? antwort(404, ['fehler' => 'Der Termin wurde nicht gefunden. Vielleicht wurde er schon geändert.']);
        $s = adminSicht($t, $p['id']);
        if ($s['ganztags']) antwort(400, ['fehler' => 'Ganztägige Einträge bitte löschen und neu eintragen.']);
        $beginn = zeitpunkt((string) ($d['tag'] ?? ''), (string) ($d['uhr'] ?? ''));
        // Dauer bleibt gleich, außer sie wurde beim Verschieben geändert
        $dauer = isset($d['dauer']) && $d['dauer'] !== '' && $d['dauer'] !== null ? (int) $d['dauer'] : intdiv($s['ende'] - $s['beginn'], 60);
        if ($dauer < 5 || $dauer > 600) antwort(400, ['fehler' => 'Bitte eine Dauer zwischen 5 und 600 Minuten angeben.']);
        $ende = $beginn + $dauer * 60;
        if (empty($d['trotzdem']) && ($k = konflikte($cfg, $nach['id'], $beginn, $ende, $s['id']))) antwort(409, ['konflikt' => $k, 'fehler' => 'Zu dieser Zeit ist schon etwas eingetragen.']);
        $priv = $t['extendedProperties']['private'] ?? [];
        $folge = (int) ($priv['folge'] ?? 0) + 1;
        $token = '';
        $aenderung = [
            'start' => ['dateTime' => rfc3339($beginn), 'timeZone' => 'Europe/Vienna'],
            'end' => ['dateTime' => rfc3339($ende), 'timeZone' => 'Europe/Vienna'],
        ];
        if (isset($priv['tb'])) {
            // Neuer Storno-Link (der alte gilt dann nicht mehr), Erinnerung für den neuen Tag zurücksetzen
            if (($priv['mail'] ?? '') !== '') $token = neuerToken();
            $aenderung['extendedProperties'] = ['private' => array_merge($priv, [
                'folge' => (string) $folge, 'erinnert' => '', 'tok' => $token !== '' ? stornoHash($token) : ($priv['tok'] ?? ''),
            ])];
        }
        terminUmziehen($cfg, $p['id'], $nach['id'], $s['id']);
        if ($s['kunde'] !== '') karteiBesuchSetzen($cfg, $s['kunde'], $s['id'], $nach['id'], $beginn, $s['leistung'], $s['leistungId'], $s['art']);
        terminAendern($cfg, $nach['id'], $s['id'], $aenderung);
        $gemailt = false;
        if (!empty($d['mail']) && $token !== '') {
            try {
                mailVerschoben($cfg, ['id' => $s['id'], 'beginn' => $beginn, 'ende' => $ende, 'leistung' => $s['leistung'] ?: $s['titel'],
                    'person' => $nach['name'], 'name' => $s['name'], 'mail' => $s['mail'], 'folge' => $folge,
                    'storno_url' => $cfg['basis_url'] . '#storno=' . $token]);
                $gemailt = true;
            } catch (Throwable $e) {
                protokoll($cfg, 'Mail fehlgeschlagen: ' . $e->getMessage());
            }
        }
        antwort(200, ['ok' => true, 'gemailt' => $gemailt]);
    }

    if ($a === 'absagen' && $methode === 'POST') {
        $d = eingabe();
        $p = personPruefen((string) ($d['person'] ?? ''));
        $t = terminHolen($cfg, $p['id'], (string) ($d['id'] ?? '')) ?? antwort(404, ['fehler' => 'Der Termin wurde nicht gefunden. Vielleicht ist er schon gelöscht.']);
        $s = adminSicht($t, $p['id']);
        terminLoeschen($cfg, $p['id'], $s['id']);
        karteiBesuchEntfernen($cfg, $s['id']);
        $gemailt = false;
        if (!empty($d['mail']) && $s['mail'] !== '' && $s['beginn'] > time()) {
            try {
                mailStorno($cfg, ['id' => $s['id'], 'beginn' => $s['beginn'], 'ende' => $s['ende'], 'leistung' => $s['leistung'],
                    'person' => person($p['id'])['name'], 'name' => $s['name'], 'mail' => $s['mail'],
                    'folge' => (int) ($t['extendedProperties']['private']['folge'] ?? 0)], true);
                $gemailt = true;
            } catch (Throwable $e) {
                protokoll($cfg, 'Mail fehlgeschlagen: ' . $e->getMessage());
            }
        }
        antwort(200, ['ok' => true, 'gemailt' => $gemailt]);
    }

    if ($a === 'suche' && $methode === 'GET') {
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) antwort(400, ['fehler' => 'Bitte mindestens 2 Zeichen eingeben.']);
        $heute = new DateTimeImmutable('today', tz());
        $von = $heute->modify('-' . REGELN['aufbewahrung'] . ' months')->getTimestamp();
        $bis = $heute->modify('+' . (REGELN['monate'] + 1) . ' months')->getTimestamp();
        $klein = mb_strtolower($q);
        $ziffern = preg_replace('/\D/', '', $q);
        $treffer = [];
        foreach (TEAM as $p) {
            // Google sucht im Titel und in der Beschreibung; danach genauer nach Name und Telefon filtern
            foreach (adminTermine($cfg, $p['id'], $von, $bis, $cfg['modus'] === 'demo' ? null : $q) as $t) {
                $s = adminSicht($t, $p['id']);
                if (!in_array($s['art'], ['online', 'telefon', 'treatwell'], true)) continue;
                $passt = str_contains(mb_strtolower($s['name']), $klein)
                    || (strlen($ziffern) >= 4 && str_contains(preg_replace('/\D/', '', $s['tel']), $ziffern))
                    || str_contains(mb_strtolower($s['mail']), $klein);
                if ($passt) $treffer[] = $s;
            }
        }
        usort($treffer, fn($x, $y) => $y['beginn'] <=> $x['beginn']);
        antwort(200, ['treffer' => array_slice($treffer, 0, 50), 'jetzt' => time()]);
    }

    /* ---------- Kundenkartei ---------- */

    if ($a === 'kunden' && $methode === 'GET') {
        $q = trim((string) ($_GET['q'] ?? ''));
        $liste = karteiLesen($cfg);
        $treffer = $q === '' ? $liste : karteSuchen($liste, $q);
        if ($q === '') usort($treffer, fn($x, $y) => strcoll($x['name'], $y['name']));
        $jetzt = time();
        antwort(200, ['kunden' => array_map(fn($k) => karteKurz($k, $jetzt), array_slice($treffer, 0, $q === '' ? 500 : 20)), 'gesamt' => count($liste)]);
    }

    if ($a === 'kunde' && $methode === 'GET') {
        $liste = karteiLesen($cfg);
        $i = karteIndex($liste, (string) ($_GET['id'] ?? ''));
        if ($i === null) antwort(404, ['fehler' => 'Diese Kundenkarte gibt es nicht mehr.']);
        antwort(200, ['kunde' => karteVoll($liste[$i], time())]);
    }

    if ($a === 'kunde-speichern' && $methode === 'POST') {
        $d = eingabe();
        [$name, $mail, $tel, $f] = pruefeKunde($d, false);
        $notiz = mb_substr(trim((string) ($d['notiz'] ?? '')), 0, 1000);
        if ($f) antwort(400, ['fehler' => 'Bitte die markierten Felder prüfen.', 'felder' => $f]);
        $id = (string) ($d['id'] ?? '');
        $id = karteiAendern($cfg, function (array &$liste) use ($id, $name, $mail, $tel, $notiz) {
            if ($id === '') {
                $k = neueKarte($name, $tel, $mail, $notiz);
                $liste[] = $k;
                return $k['id'];
            }
            $i = karteIndex($liste, $id);
            if ($i === null) return null;
            $liste[$i] = array_merge($liste[$i], ['name' => $name, 'tel' => $tel, 'mail' => $mail, 'notiz' => $notiz]);
            return $id;
        });
        if ($id === null) antwort(404, ['fehler' => 'Diese Kundenkarte gibt es nicht mehr.']);
        antwort(200, ['ok' => true, 'id' => $id]);
    }

    if ($a === 'kunde-loeschen' && $methode === 'POST') {
        $id = (string) (eingabe()['id'] ?? '');
        karteiAendern($cfg, function (array &$liste) use ($id) {
            $liste = array_values(array_filter($liste, fn($k) => $k['id'] !== $id));
        });
        antwort(200, ['ok' => true]);
    }

    if ($a === 'besuch-nachtragen' && $methode === 'POST') {
        $d = eingabe();
        $p = personPruefen((string) ($d['person'] ?? ''));
        try { $tagTs = tag((string) ($d['tag'] ?? ''))->setTime(12, 0)->getTimestamp(); } catch (InvalidArgumentException $e) { antwort(400, ['fehler' => 'Bitte ein gültiges Datum angeben.']); }
        if ($tagTs > time()) antwort(400, ['fehler' => 'Nachtragen geht nur für vergangene Besuche. Für kommende bitte einen Termin eintragen.']);
        $l = leistung((string) ($d['leistung'] ?? ''));
        $text = $l ? leistungsname($l) : trim(preg_replace('/\s+/u', ' ', (string) ($d['taetigkeit'] ?? '')));
        if (mb_strlen($text) < 2 || mb_strlen($text) > 60) antwort(400, ['fehler' => 'Bitte angeben, was gemacht wurde.']);
        $id = (string) ($d['id'] ?? '');
        $ok = karteiAendern($cfg, function (array &$liste) use ($id, $p, $tagTs, $text, $l) {
            $i = karteIndex($liste, $id);
            if ($i === null) return false;
            $liste[$i]['besuche'][] = ['id' => bin2hex(random_bytes(6)), 'terminId' => '', 'person' => $p['id'], 'beginn' => $tagTs,
                'leistung' => $text, 'leistungId' => $l['id'] ?? '', 'quelle' => 'nachgetragen'];
            return true;
        });
        if (!$ok) antwort(404, ['fehler' => 'Diese Kundenkarte gibt es nicht mehr.']);
        antwort(200, ['ok' => true]);
    }

    if ($a === 'besuch-entfernen' && $methode === 'POST') {
        $d = eingabe();
        $id = (string) ($d['id'] ?? '');
        $besuch = (string) ($d['besuch'] ?? '');
        karteiAendern($cfg, function (array &$liste) use ($id, $besuch) {
            $i = karteIndex($liste, $id);
            if ($i === null) return;
            // Nur nachgetragene Besuche; Besuche zu Terminen verschwinden mit dem Absagen des Termins
            $liste[$i]['besuche'] = array_values(array_filter($liste[$i]['besuche'], fn($b) => $b['id'] !== $besuch || !empty($b['terminId'])));
        });
        antwort(200, ['ok' => true]);
    }

    /* ---------- Excel: Vorlage, Export, Import ---------- */

    if (($a === 'vorlage' || $a === 'export') && $methode === 'GET') {
        require_once __DIR__ . '/lib/tabelle.php';
        $kopf = ['Name', 'Telefon', 'E-Mail', 'Letzter Besuch', 'Letzte Leistung', 'Bei', 'Notiz'];
        if ($a === 'vorlage') {
            $zeilen = [$kopf,
                ['Maria Muster', '0664 123 45 67', 'maria@beispiel.at', '12.09.2026', 'Damen – Waschen, Schneiden & Föhnen', 'Andrea', 'Farbe 6/0, mag es kürzer'],
                ['Franz Beispiel', '0463 12 34 56', '', '03.10.2026', 'Herren – Trockenhaarschnitt', 'Pamela', ''],
            ];
            $datei = 'Kundenkartei-Vorlage.xlsx';
        } else {
            $jetzt = time();
            $zeilen = [array_merge($kopf, ['Nächster Termin', 'Besuche', 'Wird gelöscht am'])];
            $liste = karteiLesen($cfg);
            usort($liste, fn($x, $y) => strcoll($x['name'], $y['name']));
            $datum = fn(?array $b) => $b ? (new DateTimeImmutable($b['tag']))->format('d.m.Y') : '';
            foreach ($liste as $k) {
                $v = karteVoll($k, $jetzt);
                $zeilen[] = [$v['name'], $v['tel'], $v['mail'], $datum($v['letzter']), $v['letzter']['leistung'] ?? '', $v['letzter']['personName'] ?? '',
                    $v['notiz'], $v['naechster'] ? $datum($v['naechster']) . ($v['naechster']['uhr'] ? ' ' . $v['naechster']['uhr'] : '') : '', (string) $v['anzahl'],
                    (new DateTimeImmutable($v['loeschenAm']))->format('d.m.Y')];
            }
            $datei = 'Kundenkartei-' . date('Y-m-d') . '.xlsx';
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $datei . '"');
        echo xlsxSchreiben($zeilen, 'Kunden', [26, 18, 28, 15, 38, 10, 40, 18, 9, 16]);
        exit;
    }

    if ($a === 'import-pruefen' && $methode === 'POST') {
        require_once __DIR__ . '/lib/tabelle.php';
        $f = $_FILES['datei'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) antwort(400, ['fehler' => 'Bitte eine Datei auswählen.']);
        if ($f['size'] > 5 * 1024 * 1024) antwort(400, ['fehler' => 'Die Datei ist zu groß (höchstens 5 MB).']);
        try {
            $tabelle = tabelleLesen((string) file_get_contents($f['tmp_name']), (string) $f['name']);
        } catch (Throwable $e) {
            antwort(400, ['fehler' => 'Die Datei konnte nicht gelesen werden. ' . $e->getMessage()]);
        }
        antwort(200, importAuswerten($cfg, $tabelle));
    }

    if ($a === 'import-uebernehmen' && $methode === 'POST') {
        $d = eingabe(2 * 1024 * 1024);
        $zeilen = array_slice(is_array($d['zeilen'] ?? null) ? $d['zeilen'] : [], 0, 3000);
        // Noch einmal prüfen, damit nur saubere Daten übernommen werden
        $geprueft = importAuswerten($cfg, array_merge([['Name', 'Telefon', 'E-Mail', 'Letzter Besuch', 'Letzte Leistung', 'Bei', 'Notiz']],
            array_map(fn($z) => [(string) ($z['name'] ?? ''), (string) ($z['tel'] ?? ''), (string) ($z['mail'] ?? ''), (string) ($z['datum'] ?? ''),
                (string) ($z['leistung'] ?? ''), (string) ($z['personName'] ?? ''), (string) ($z['notiz'] ?? '')], $zeilen)));
        $ergebnis = karteiAendern($cfg, function (array &$liste) use ($geprueft) {
            $neu = 0; $ergaenzt = 0;
            foreach ($geprueft['zeilen'] as $z) {
                if ($z['status'] === 'fehler') continue;
                $kid = importPassendeKarte($liste, $z);
                if ($kid === null) {
                    $k = neueKarte($z['name'], $z['tel'], $z['mail'], $z['notiz']);
                    $liste[] = $k;
                    $i = count($liste) - 1;
                    $neu++;
                } else {
                    $i = karteIndex($liste, $kid);
                    // Nur leere Felder ergänzen, Notiz anhängen, nichts überschreiben
                    if (($liste[$i]['tel'] ?? '') === '' && $z['tel'] !== '') $liste[$i]['tel'] = $z['tel'];
                    if (($liste[$i]['mail'] ?? '') === '' && $z['mail'] !== '') $liste[$i]['mail'] = $z['mail'];
                    if ($z['notiz'] !== '' && !str_contains($liste[$i]['notiz'] ?? '', $z['notiz'])) {
                        $liste[$i]['notiz'] = mb_substr(trim(($liste[$i]['notiz'] ?? '') . "\n" . $z['notiz']), 0, 1000);
                    }
                    $ergaenzt++;
                }
                if ($z['beginn']) {
                    $schonDa = false;
                    foreach ($liste[$i]['besuche'] as $b) {
                        if (abs($b['beginn'] - $z['beginn']) < 86400) $schonDa = true;
                    }
                    if (!$schonDa) {
                        $liste[$i]['besuche'][] = ['id' => bin2hex(random_bytes(6)), 'terminId' => '', 'person' => $z['person'],
                            'beginn' => $z['beginn'], 'leistung' => $z['leistungText'], 'leistungId' => $z['leistungId'], 'quelle' => 'nachgetragen'];
                    }
                }
            }
            return ['neu' => $neu, 'ergaenzt' => $ergaenzt];
        });
        antwort(200, ['ok' => true] + $ergebnis);
    }

    /* Bestehenden Termin einer (neuen oder bestehenden) Kundenkarte zuordnen. */
    if ($a === 'zuordnen' && $methode === 'POST') {
        $d = eingabe();
        $p = personPruefen((string) ($d['person'] ?? ''));
        $t = terminHolen($cfg, $p['id'], (string) ($d['termin'] ?? '')) ?? antwort(404, ['fehler' => 'Der Termin wurde nicht gefunden.']);
        $s = adminSicht($t, $p['id']);
        if (!in_array($s['art'], ['online', 'telefon', 'treatwell'], true)) antwort(400, ['fehler' => 'Nur Kundentermine lassen sich zuordnen.']);
        $kundeId = (string) ($d['kundeId'] ?? '');
        if ($kundeId === '') {
            $karte = neueKarte($s['name'], $s['tel'], $s['mail'], '');
            karteiAendern($cfg, function (array &$liste) use ($karte) { $liste[] = $karte; });
            $kundeId = $karte['id'];
        } elseif (karteIndex(karteiLesen($cfg), $kundeId) === null) {
            antwort(404, ['fehler' => 'Diese Kundenkarte gibt es nicht mehr.']);
        }
        $priv = $t['extendedProperties']['private'] ?? [];
        terminAendern($cfg, $p['id'], $s['id'], ['extendedProperties' => ['private' => array_merge($priv, ['kunde' => $kundeId])]]);
        karteiBesuchSetzen($cfg, $kundeId, $s['id'], $p['id'], $s['beginn'], $s['leistung'], $s['leistungId'], $s['art']);
        antwort(200, ['ok' => true, 'kundeId' => $kundeId]);
    }

    /* ---------- Treatwell-E-Mails ---------- */

    if ($a === 'treatwell-abruf' && $methode === 'POST') {
        // Höchstens alle 2 Minuten wirklich ins Postfach schauen
        $neu = 0;
        if (time() - twZustand($cfg)['letzterAbruf'] >= 120) $neu = treatwellAbrufen($cfg)['neu'];
        antwort(200, ['neu' => $neu, 'treatwell' => twStatus($cfg)]);
    }

    if ($a === 'hinweis-erledigt' && $methode === 'POST') {
        $id = (string) (eingabe()['id'] ?? '');
        $z = twZustand($cfg);
        $z['hinweise'] = array_values(array_filter($z['hinweise'], fn($h) => $h['id'] !== $id));
        twZustandSpeichern($cfg, $z);
        antwort(200, ['ok' => true, 'treatwell' => twStatus($cfg)]);
    }

    if ($a === 'pause' && $methode === 'POST') {
        $d = eingabe();
        $e = einstellungen($cfg);
        $ids = ($d['person'] ?? '') === 'alle' ? array_column(TEAM, 'id') : [personPruefen((string) ($d['person'] ?? ''))['id']];
        foreach ($ids as $id) $e['pausiert'][$id] = !empty($d['pausiert']);
        einstellungenSpeichern($cfg, $e);
        antwort(200, ['ok' => true, 'pausiert' => $e['pausiert']]);
    }

    antwort(404, ['fehler' => 'Unbekannte Anfrage']);
} catch (KalenderFehler $e) {
    protokoll($cfg, $e->getMessage());
    antwort(503, ['fehler' => 'Der Google Kalender ist gerade nicht erreichbar. Bitte gleich noch einmal versuchen.']);
}
