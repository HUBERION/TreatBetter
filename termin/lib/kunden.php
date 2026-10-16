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

/* ---------- Import aus Excel/CSV ---------- */

/* Passende vorhandene Karte zu einer Importzeile: gleiche E-Mail oder Telefonnummer,
   sonst gleicher Name, wenn keine widersprüchlichen Kontaktdaten da sind. */
function importPassendeKarte(array $liste, array $z): ?string
{
    $id = karteZuKontakt($liste, $z['mail'], $z['tel']);
    if ($id !== null) return $id;
    $name = mb_strtolower($z['name']);
    foreach ($liste as $k) {
        if (mb_strtolower(trim($k['name'])) !== $name) continue;
        $telKonflikt = $z['tel'] !== '' && ($k['tel'] ?? '') !== '' && telNorm($z['tel']) !== telNorm($k['tel']);
        $mailKonflikt = $z['mail'] !== '' && ($k['mail'] ?? '') !== '' && mb_strtolower($z['mail']) !== mb_strtolower($k['mail']);
        if (!$telKonflikt && !$mailKonflikt) return $k['id'];
    }
    return null;
}

/* Datum aus der Tabelle: TT.MM.JJJJ, TT.MM.JJ, JJJJ-MM-TT oder Excel-Zahl. null = leer, -1 = nicht erkannt. */
function importDatum(string $s): ?int
{
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^\d{4,5}(\.\d+)?$/', $s) && (float) $s > 20000 && (float) $s < 80000) {
        // Excel speichert Datumswerte als Tage seit dem 30.12.1899
        return (new DateTimeImmutable('1899-12-30', tz()))->modify('+' . (int) $s . ' days')->setTime(12, 0)->getTimestamp();
    }
    if (preg_match('/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{2}|\d{4})$/', $s, $m)) {
        $j = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
        if ($j > (int) date('Y') + 1) $j -= 100;
        $tag = (int) $m[1];
        $monat = (int) $m[2];
    } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
        $j = (int) $m[1];
        $monat = (int) $m[2];
        $tag = (int) $m[3];
    } else {
        return -1;
    }
    if (!checkdate($monat, $tag, $j)) return -1;
    return (new DateTimeImmutable('now', tz()))->setDate($j, $monat, $tag)->setTime(12, 0)->getTimestamp();
}

/* Tabelle prüfen und für die Vorschau aufbereiten. */
function importAuswerten(array $cfg, array $tabelle): array
{
    $tabelle = array_values(array_filter($tabelle, fn($z) => implode('', array_map('trim', $z)) !== ''));
    if (!$tabelle) return ['zeilen' => [], 'neu' => 0, 'ergaenzen' => 0, 'fehler' => 0, 'abgeschnitten' => false];

    // Spalten anhand der Überschriften erkennen (sonst Reihenfolge wie in der Vorlage)
    $muster = [
        'vorname' => '/^vorname/', 'nachname' => '/^(nachname|familienname|zuname)/',
        'name' => '/^(name|kunde|kundin|vor- und nachname)/', 'tel' => '/(tel|handy|mobil|nummer)/', 'mail' => '/mail/',
        'datum' => '/(datum|besuch|zuletzt)/', 'leistung' => '/(leistung|behandlung|dienst)/', 'person' => '/^(bei|friseur|mitarbeit)/',
        'notiz' => '/(notiz|bemerk|info|farb|rezept|anmerk)/',
    ];
    $spalten = [];
    foreach (array_map(fn($v) => mb_strtolower(trim((string) $v)), $tabelle[0]) as $i => $titel) {
        foreach ($muster as $feld => $re) {
            if (!isset($spalten[$feld]) && preg_match($re, $titel)) { $spalten[$feld] = $i; break; }
        }
    }
    $mitKopf = isset($spalten['name']) || isset($spalten['nachname']);
    if (!$mitKopf) $spalten = ['name' => 0, 'tel' => 1, 'mail' => 2, 'datum' => 3, 'leistung' => 4, 'person' => 5, 'notiz' => 6];
    $daten = $mitKopf ? array_slice($tabelle, 1) : $tabelle;
    $wert = fn(array $z, string $feld) => isset($spalten[$feld]) ? trim(preg_replace('/\s+/u', ' ', (string) ($z[$spalten[$feld]] ?? ''))) : '';

    $vorhanden = karteiLesen($cfg);
    $jetzt = time();
    $out = [];
    $zaehler = ['neu' => 0, 'ergaenzen' => 0, 'fehler' => 0];
    foreach (array_slice($daten, 0, 3000) as $n => $z) {
        $name = $wert($z, 'name');
        if (isset($spalten['vorname']) || isset($spalten['nachname'])) $name = trim($wert($z, 'vorname') . ' ' . $wert($z, 'nachname')) ?: $name;
        $tel = $wert($z, 'tel');
        $mail = $wert($z, 'mail');
        $notiz = isset($spalten['notiz']) ? mb_substr(trim((string) ($z[$spalten['notiz']] ?? '')), 0, 1000) : '';
        $datumText = $wert($z, 'datum');
        $leistungText = mb_substr($wert($z, 'leistung'), 0, 60);
        $personText = $wert($z, 'person');
        $fehler = [];
        $hinweise = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) $fehler[] = 'Name fehlt';
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) { $hinweise[] = 'E-Mail ungültig, wird weggelassen'; $mail = ''; }
        if ($tel !== '' && preg_match_all('/\d/', $tel) < 6) { $hinweise[] = 'Telefon ungültig, wird weggelassen'; $tel = ''; }
        $tel = mb_substr($tel, 0, 25);
        $beginn = importDatum($datumText);
        if ($beginn === -1) { $hinweise[] = 'Datum nicht erkannt, wird weggelassen'; $beginn = null; }
        elseif ($beginn !== null && $beginn > $jetzt) { $hinweise[] = 'Datum liegt in der Zukunft, wird weggelassen'; $beginn = null; }
        if ($beginn !== null && $leistungText === '') $leistungText = 'Besuch';
        if ($beginn === null && $leistungText !== '') $hinweise[] = 'Leistung ohne gültiges Datum wird nicht übernommen';
        // Leistung aus der Liste erkennen (mit oder ohne „Damen –“ davor)
        $leistungId = '';
        $klein = mb_strtolower($leistungText);
        foreach (LEISTUNGEN as $l) {
            if ($klein !== '' && ($klein === mb_strtolower(leistungsname($l)) || $klein === mb_strtolower($l['name']))) {
                $leistungId = $l['id'];
                $leistungText = leistungsname($l);
                break;
            }
        }
        $person = '';
        foreach (TEAM as $p) if ($personText !== '' && str_starts_with(mb_strtolower($personText), mb_strtolower($p['name']))) $person = $p['id'];
        $zeile = [
            'zeile' => $n + ($mitKopf ? 2 : 1), 'name' => $name, 'tel' => $tel, 'mail' => $mail, 'notiz' => $notiz,
            'datum' => $beginn ? (new DateTimeImmutable('@' . $beginn))->setTimezone(tz())->format('d.m.Y') : '', 'beginn' => $beginn,
            'leistung' => $leistungText, 'leistungText' => $leistungText, 'leistungId' => $leistungId,
            'person' => $person, 'personName' => $person ? person($person)['name'] : '',
            'fehler' => implode(', ', $fehler), 'hinweis' => implode(', ', $hinweise),
        ];
        if ($fehler) {
            $zeile['status'] = 'fehler';
        } else {
            $zeile['status'] = importPassendeKarte($vorhanden, $zeile) !== null ? 'ergaenzen' : 'neu';
            // Auch doppelte Zeilen in der Datei selbst erkennen
            if ($zeile['status'] === 'neu') $vorhanden[] = neueKarte($name, $tel, $mail, '');
        }
        $zaehler[$zeile['status']]++;
        $out[] = $zeile;
    }
    return ['zeilen' => $out] + $zaehler + ['abgeschnitten' => count($daten) > 3000];
}
