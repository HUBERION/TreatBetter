<?php
/* Kundenkartei des Salons (Stammkund:innen). Liegt als daten/kunden.json im privaten Ordner, nicht bei Google.
   Eine Karte: Name, Telefon, E-Mail, Notiz (Arbeitshinweise, keine Gesundheitsdaten) und die Besuche.
   Ein Besuch gehört entweder zu einem Termin im Kalender (terminId) oder wurde von Hand nachgetragen. */

declare(strict_types=1);

require_once __DIR__ . '/salon.php';
require_once __DIR__ . '/zeit.php';

const KARTEI_LOESCHEN_JAHRE = 3; // Karten ohne Besuch seit so vielen Jahren werden gelöscht

function karteiDatei(array $cfg): string
{
    return $cfg['daten'] . '/kunden.json';
}

function karteiLesen(array $cfg): array
{
    $f = karteiDatei($cfg);
    return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
}

/* Kartei unter Dateisperre ändern: $fn bekommt die Liste per Referenz, ihr Rückgabewert wird durchgereicht. */
function karteiAendern(array $cfg, callable $fn)
{
    $h = fopen(karteiDatei($cfg), 'c+');
    flock($h, LOCK_EX);
    $inhalt = stream_get_contents($h);
    $liste = $inhalt ? (json_decode($inhalt, true) ?: []) : [];
    $ergebnis = $fn($liste);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode(array_values($liste), JSON_UNESCAPED_UNICODE));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    @chmod(karteiDatei($cfg), 0600);
    return $ergebnis;
}

/* Telefonnummer vergleichbar machen: nur Ziffern, österreichische Vorwahl einheitlich. */
function telNorm(string $tel): string
{
    $z = preg_replace('/\D/', '', $tel);
    if (str_starts_with($z, '00')) $z = substr($z, 2);
    elseif (str_starts_with($z, '0')) $z = '43' . substr($z, 1);
    return $z;
}

function karteIndex(array $liste, string $id): ?int
{
    foreach ($liste as $i => $k) if ($k['id'] === $id) return $i;
    return null;
}

/* Passende Karte zu E-Mail oder Telefonnummer (für die automatische Zuordnung von Online-Buchungen). */
function karteZuKontakt(array $liste, string $mail, string $tel): ?string
{
    $mail = mb_strtolower(trim($mail));
    $t = telNorm($tel);
    foreach ($liste as $k) {
        if ($mail !== '' && mb_strtolower($k['mail'] ?? '') === $mail) return $k['id'];
        if (strlen($t) >= 8 && telNorm($k['tel'] ?? '') === $t) return $k['id'];
    }
    return null;
}

function karteSuchen(array $liste, string $q): array
{
    $klein = mb_strtolower(trim($q));
    $ziffern = preg_replace('/\D/', '', $q);
    $treffer = [];
    foreach ($liste as $k) {
        $name = mb_strtolower($k['name']);
        $punkte = 0;
        if ($klein !== '' && str_starts_with($name, $klein)) $punkte = 3;
        elseif ($klein !== '' && preg_match('/(^|\s)' . preg_quote($klein, '/') . '/u', $name)) $punkte = 2;
        elseif ($klein !== '' && str_contains($name, $klein)) $punkte = 1;
        elseif (strlen($ziffern) >= 4 && str_contains(preg_replace('/\D/', '', $k['tel'] ?? ''), $ziffern)) $punkte = 2;
        elseif ($klein !== '' && str_contains(mb_strtolower($k['mail'] ?? ''), $klein)) $punkte = 1;
        if ($punkte) $treffer[] = [$punkte, $k];
    }
    usort($treffer, fn($a, $b) => $b[0] <=> $a[0] ?: strcoll($a[1]['name'], $b[1]['name']));
    return array_map(fn($t) => $t[1], $treffer);
}

/* Kurzfassung für Listen und Vorschläge: letzter und nächster Besuch. */
function karteKurz(array $k, int $jetzt): array
{
    $besuche = $k['besuche'] ?? [];
    usort($besuche, fn($a, $b) => $a['beginn'] <=> $b['beginn']);
    $letzter = null;
    $naechster = null;
    foreach ($besuche as $b) {
        if ($b['beginn'] <= $jetzt) $letzter = $b;
        elseif ($naechster === null) $naechster = $b;
    }
    return [
        'id' => $k['id'], 'name' => $k['name'], 'tel' => $k['tel'] ?? '', 'mail' => $k['mail'] ?? '',
        'letzter' => $letzter ? besuchSicht($letzter) : null,
        'naechster' => $naechster ? besuchSicht($naechster) : null,
        'anzahl' => count(array_filter($besuche, fn($b) => $b['beginn'] <= $jetzt)),
    ];
}

function besuchSicht(array $b): array
{
    $p = person($b['person'] ?? '');
    return [
        'id' => $b['id'], 'beginn' => $b['beginn'],
        'tag' => (new DateTimeImmutable('@' . $b['beginn']))->setTimezone(tz())->format('Y-m-d'),
        'uhr' => !empty($b['terminId']) ? hhmm($b['beginn']) : '',
        'leistung' => $b['leistung'] ?? '', 'leistungId' => $b['leistungId'] ?? '',
        'person' => $b['person'] ?? '', 'personName' => $p['name'] ?? '',
        'terminId' => $b['terminId'] ?? '', 'quelle' => $b['quelle'] ?? '',
    ];
}

function karteVoll(array $k, int $jetzt): array
{
    $besuche = array_map('besuchSicht', $k['besuche'] ?? []);
    usort($besuche, fn($a, $b) => $b['beginn'] <=> $a['beginn']);
    $letzte = max(array_merge([$k['angelegt'] ?? 0], array_map(fn($b) => min($b['beginn'], $jetzt), $k['besuche'] ?? [])));
    return karteKurz($k, $jetzt) + [
        'notiz' => $k['notiz'] ?? '', 'angelegt' => $k['angelegt'] ?? 0, 'besuche' => $besuche,
        'loeschenAm' => (new DateTimeImmutable('@' . $letzte))->setTimezone(tz())->modify('+' . KARTEI_LOESCHEN_JAHRE . ' years')->format('Y-m-d'),
    ];
}

/* Besuch zu einem Kalendertermin eintragen oder aktualisieren (gleiche terminId = derselbe Besuch). */
function karteiBesuchSetzen(array $cfg, string $kundeId, string $terminId, string $person, int $beginn, string $leistung, string $leistungId, string $quelle): void
{
    karteiAendern($cfg, function (array &$liste) use ($kundeId, $terminId, $person, $beginn, $leistung, $leistungId, $quelle) {
        $i = karteIndex($liste, $kundeId);
        if ($i === null) return;
        foreach ($liste[$i]['besuche'] as &$b) {
            if (($b['terminId'] ?? '') === $terminId) {
                $b = array_merge($b, ['person' => $person, 'beginn' => $beginn, 'leistung' => $leistung, 'leistungId' => $leistungId]);
                return;
            }
        }
        unset($b);
        $liste[$i]['besuche'][] = ['id' => bin2hex(random_bytes(6)), 'terminId' => $terminId, 'person' => $person,
            'beginn' => $beginn, 'leistung' => $leistung, 'leistungId' => $leistungId, 'quelle' => $quelle];
    });
}

/* Besuch zu einem abgesagten Termin entfernen (in allen Karten, falls die Zuordnung unbekannt ist). */
function karteiBesuchEntfernen(array $cfg, string $terminId): void
{
    if ($terminId === '' || !is_file(karteiDatei($cfg))) return;
    karteiAendern($cfg, function (array &$liste) use ($terminId) {
        foreach ($liste as &$k) {
            $k['besuche'] = array_values(array_filter($k['besuche'] ?? [], fn($b) => ($b['terminId'] ?? '') !== $terminId));
        }
    });
}

function neueKarte(string $name, string $tel, string $mail, string $notiz): array
{
    return ['id' => bin2hex(random_bytes(8)), 'name' => $name, 'tel' => $tel, 'mail' => $mail, 'notiz' => $notiz,
        'angelegt' => time(), 'besuche' => []];
}

/* Karten löschen, deren letzter Besuch (bzw. Anlage) mehr als 3 Jahre zurückliegt. Liefert die Anzahl. */
function karteiAufraeumen(array $cfg, int $jetzt): int
{
    if (!is_file(karteiDatei($cfg))) return 0;
    $grenze = (new DateTimeImmutable('@' . $jetzt))->modify('-' . KARTEI_LOESCHEN_JAHRE . ' years')->getTimestamp();
    return karteiAendern($cfg, function (array &$liste) use ($grenze, $jetzt) {
        $vorher = count($liste);
        $liste = array_values(array_filter($liste, function ($k) use ($grenze, $jetzt) {
            $letzte = max(array_merge([$k['angelegt'] ?? 0], array_map(fn($b) => min($b['beginn'], $jetzt), $k['besuche'] ?? [])));
            return $letzte >= $grenze;
        }));
        return $vorher - count($liste);
    });
}
