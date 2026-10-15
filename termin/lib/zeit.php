<?php
/* Öffnungszeiten, Feiertage und Berechnung der freien Termine.
   Reine Funktionen ohne Zugriff auf Google, damit sie sich testen lassen.
   Alle Zeitpunkte als Unix-Zeitstempel, Tage als 'YYYY-MM-DD' in Europe/Vienna. */

declare(strict_types=1);

require_once __DIR__ . '/salon.php';

function tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('Europe/Vienna');
}

function tag(string $key): DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $key, tz());
    if (!$d || $d->format('Y-m-d') !== $key) throw new InvalidArgumentException('Ungültiges Datum');
    return $d;
}

/* Zeitpunkt „HH:MM“ an einem Tag, sommerzeitsicher. */
function um(string $key, string $hhmm): int
{
    return tag($key)->setTime((int) substr($hhmm, 0, 2), (int) substr($hhmm, 3, 2))->getTimestamp();
}

function ostersonntag(int $y): DateTimeImmutable
{
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $n = $h + $l - 7 * $m + 114;
    return (new DateTimeImmutable('now', tz()))->setDate($y, intdiv($n, 31), $n % 31 + 1)->setTime(0, 0);
}

/* Gesetzliche Feiertage in Österreich: 'YYYY-MM-DD' => Name. */
function feiertage(int $y): array
{
    static $cache = [];
    if (isset($cache[$y])) return $cache[$y];
    $map = [];
    foreach (['01-01' => 'Neujahr', '01-06' => 'Heilige Drei Könige', '05-01' => 'Staatsfeiertag', '08-15' => 'Mariä Himmelfahrt',
        '10-26' => 'Nationalfeiertag', '11-01' => 'Allerheiligen', '12-08' => 'Mariä Empfängnis', '12-25' => 'Christtag',
        '12-26' => 'Stefanitag'] as $md => $name) {
        $map["$y-$md"] = $name;
    }
    $o = ostersonntag($y);
    foreach ([1 => 'Ostermontag', 39 => 'Christi Himmelfahrt', 50 => 'Pfingstmontag', 60 => 'Fronleichnam'] as $off => $name) {
        $map[$o->modify("+$off days")->format('Y-m-d')] = $name;
    }
    return $cache[$y] = $map;
}

/* Öffnungszeit eines Tages: ['von' => 'HH:MM', 'bis' => 'HH:MM'] oder ['zu' => true, 'hinweis' => …]. */
function oeffnung(string $key): array
{
    $d = tag($key);
    $wt = (int) $d->format('w');
    $regulaer = OEFFNUNG[$wt] ?? null;
    if ($regulaer && $d->format('m-d') === '12-24') return ['von' => '08:30', 'bis' => '12:00', 'hinweis' => 'Heiliger Abend'];
    $f = feiertage((int) $d->format('Y'))[$key] ?? null;
    if ($f) return ['zu' => true, 'hinweis' => $f];
    if (!$regulaer) return ['zu' => true, 'hinweis' => ''];
    return ['von' => $regulaer[0], 'bis' => $regulaer[1]];
}

/* Letzter Tag, der noch buchbar ist (heute + 3 Monate). */
function letzterTag(int $jetzt): string
{
    return (new DateTimeImmutable('@' . $jetzt))->setTimezone(tz())->modify('+' . REGELN['monate'] . ' months')->format('Y-m-d');
}

/* Freie Startzeiten (Zeitstempel) einer Person an einem Tag.
   $belegt: Liste von [beginn, ende] als Zeitstempel. */
function freieZeiten(array $person, string $key, int $dauer, array $belegt, int $jetzt): array
{
    $o = oeffnung($key);
    if (!empty($o['zu'])) return [];
    if (!in_array((int) tag($key)->format('N'), $person['tage'], true)) return [];
    if ($key > letzterTag($jetzt)) return [];
    $von = um($key, $o['von']);
    $ende = um($key, $o['bis']);
    if ($person['bis'] !== null) $ende = min($ende, um($key, $person['bis']));
    $fruehestens = $jetzt + REGELN['vorlauf'] * 60;
    $puffer = REGELN['puffer'] * 60;
    $raster = REGELN['raster'] * 60;
    $d = $dauer * 60;
    $frei = [];
    for ($t = $von; $t + $d <= $ende; $t += $raster) {
        if ($t < $fruehestens) continue;
        foreach ($belegt as [$b, $e]) {
            if ($t < $e + $puffer && $t + $d + $puffer > $b) continue 2;
        }
        $frei[] = $t;
    }
    return $frei;
}

/* Freie Zeiten aller gewählten Personen zusammenführen: Zeitstempel => [person-ids]. */
function freieZeitenTeam(array $personen, string $key, int $dauer, array $belegtJePerson, int $jetzt): array
{
    $map = [];
    foreach ($personen as $p) {
        foreach (freieZeiten($p, $key, $dauer, $belegtJePerson[$p['id']] ?? [], $jetzt) as $t) {
            $map[$t][] = $p['id'];
        }
    }
    ksort($map);
    return $map;
}

function hhmm(int $ts): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(tz())->format('H:i');
}
