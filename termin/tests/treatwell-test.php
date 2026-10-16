<?php
/* Tests für das Übernehmen von Treatwell-Buchungs-E-Mails. Aufruf: php termin/tests/treatwell-test.php
   Die Beispiele in tests/treatwell/ sind echte Treatwell-E-Mails mit erfundenen Kundendaten. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/treatwell.php';

$fehler = 0;
function pruefe(string $was, $ist, $soll): void
{
    global $fehler;
    if ($ist === $soll) { echo "ok   $was\n"; return; }
    $fehler++;
    echo "FEHLER $was\n  ist:  " . json_encode($ist, JSON_UNESCAPED_UNICODE) . "\n  soll: " . json_encode($soll, JSON_UNESCAPED_UNICODE) . "\n";
}

function beispiel(int $i): array
{
    $txt = file_get_contents(__DIR__ . "/treatwell/buchung$i.txt");
    preg_match('/^Betreff:\s*(.+)$/m', $txt, $m);
    return [trim($m[1]), $txt, file_get_contents(__DIR__ . "/treatwell/buchung$i.html")];
}

/* Wie eine echte E-Mail, die nur einen HTML-Teil hat. */
function alsMail(string $betreff, string $html): string
{
    return "From: Treatwell <noreply@treatwell.at>\r\nSubject: =?UTF-8?B?" . base64_encode($betreff) . "?=\r\nMessage-ID: <test-" . md5($html) . "@treatwell.at>\r\n"
        . "Date: Wed, 30 Sep 2026 17:01:02 +0200\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"xyz\"\r\n\r\n"
        . "--xyz\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--xyz--\r\n";
}

[$b1, $t1, $h1] = beispiel(1);
[$b2, $t2, $h2] = beispiel(2);

foreach (['Text' => [$t1, $t2], 'HTML' => [mailText(alsMail($b1, $h1))['text'], mailText(alsMail($b2, $h2))['text']]] as $weg => [$x1, $x2]) {
    $e = treatwellLesen($b1, $x1);
    pruefe("$weg 1: Art", $e['art'], 'neu');
    pruefe("$weg 1: Bestellnummer", $e['nr'], 'T2194729034');
    pruefe("$weg 1: Leistung", $e['leistungen'], [['name' => 'Herren - Trockenhaarschnitt', 'min' => 20]]);
    pruefe("$weg 1: Beginn", date('Y-m-d H:i', $e['beginn']), '2026-10-02 08:00');
    pruefe("$weg 1: bei Pamela", $e['person'], 'pamela');
    pruefe("$weg 1: vorab bezahlt", $e['bezahlt'], true);
    pruefe("$weg 1: Name ohne „Neu“", $e['name'], 'Max Mustermann');
    pruefe("$weg 1: E-Mail", $e['mail'], 'max.muster@example.com');
    pruefe("$weg 1: Telefon", $e['tel'], '+43 660 1234567');
    pruefe("$weg 1: nichts fehlt", $e['fehlt'], []);
    $e = treatwellLesen($b2, $x2);
    pruefe("$weg 2: Leistung", $e['leistungen'], [['name' => 'Damen - Trockenhaarschnitt', 'min' => 25]]);
    pruefe("$weg 2: Beginn", date('Y-m-d H:i', $e['beginn']), '2026-10-09 13:35');
    pruefe("$weg 2: bei Andrea", $e['person'], 'andrea');
    pruefe("$weg 2: nicht bezahlt", $e['bezahlt'], false);
    pruefe("$weg 2: Name", $e['name'], 'Erika Beispiel');
}

// Verschiebung (echte E-Mail „TERMIN VERSCHOBEN“, Paket mit Einwirkzeit, „Gastname“, ohne Kundenkontakt)
$tv = file_get_contents(__DIR__ . '/treatwell/verschoben.txt');
preg_match('/^Betreff:\s*(.+)$/m', $tv, $m);
$bv = trim($m[1]);
foreach (['Text' => $tv, 'HTML' => mailText(alsMail($bv, file_get_contents(__DIR__ . '/treatwell/verschoben.html')))['text']] as $weg => $x) {
    $e = treatwellLesen($bv, $x);
    pruefe("$weg verschoben: Art", $e['art'], 'aenderung');
    pruefe("$weg verschoben: Bestellnummer", $e['nr'], 'T2192396359');
    pruefe("$weg verschoben: Paket mit 1 Std. 25 Min. + 30 Min. Einwirkzeit", $e['leistungen'][0]['min'] ?? null, 115);
    pruefe("$weg verschoben: Paketname", $e['leistungen'][0]['name'] ?? null, 'Paket - Ansatzfarbe, Waschen, Pflege, Schneiden & Föhnen');
    pruefe("$weg verschoben: neuer Beginn", date('Y-m-d H:i', (int) $e['beginn']), '2026-09-12 08:00');
    pruefe("$weg verschoben: Gastname ohne „Neu“", $e['name'], 'Clara Testfall-Muster');
    pruefe("$weg verschoben: keine Salon-Adresse als Kunden-E-Mail", $e['mail'], '');
    pruefe("$weg verschoben: nichts fehlt", $e['fehlt'], []);
}
pruefe('Dauer „20 minuten“', twDauer('20 minuten '), 20);
pruefe('Dauer „1 stunde“', twDauer('1 stunde'), 60);

pruefe('Leistung zuordnen', treatwellLeistung('Herren - Trockenhaarschnitt')['id'] ?? null, 'herren-trocken');
pruefe('Leistung zuordnen (Treatwell-Schreibweise)', treatwellLeistung('Studenten  - Waschen, Schneiden & Föhnen')['id'] ?? null, 'stud-wsf');
pruefe('Stornierung erkennen', array_intersect_key(treatwellLesen('Stornierte Treatwell Buchung (Unsere Ref. T2194729034)', 'Ihre Buchung wurde storniert.'), ['art' => 1, 'nr' => 1]), ['art' => 'storno', 'nr' => 'T2194729034']);
pruefe('Fremde E-Mail ignorieren', treatwellLesen('Ihre monatliche Rechnung', 'Rechnung')['art'], 'fremd');

/* ---------- Anwenden im Demo-Kalender ---------- */
$daten = sys_get_temp_dir() . '/tb-twtest-' . getmypid();
@mkdir($daten, 0700, true);
file_put_contents("$daten/demo-kalender.json", json_encode(['andrea' => [], 'pamela' => [
    // Von Hand eingetragener Treatwell-Termin für Buchung 1 (noch ohne Bestellnummer)
    ['id' => 'hand1', 'status' => 'confirmed', 'start' => ['dateTime' => '2026-10-02T08:00:00+02:00'], 'end' => ['dateTime' => '2026-10-02T08:20:00+02:00'],
        'extendedProperties' => ['private' => ['tb' => '1', 'quelle' => 'treatwell', 'name' => 'Max Mustermann']]],
]]));
$cfg = ['modus' => 'demo', 'daten' => $daten, 'kalender' => ['andrea' => 'a', 'pamela' => 'p']];
/* Text- und HTML-Teil unterschiedlich: fehlt im Textteil der Name, zählt die HTML-Fassung */
$hv = file_get_contents(__DIR__ . '/treatwell/verschoben.html');
$beide = "From: Treatwell <noreply@treatwell.at>\r\nSubject: =?UTF-8?B?" . base64_encode($bv) . "?=\r\nMessage-ID: <test-beide@treatwell.at>\r\n"
    . "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"xyz\"\r\n\r\n"
    . "--xyz\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . preg_replace('/Gastname.*/u', '', $tv) . "\r\n"
    . "--xyz\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($hv)) . "--xyz--\r\n";
$m = mailText($beide);
pruefe('Textteil allein: Name fehlt', in_array('Kundenname', treatwellLesen($m['betreff'], $m['text'])['fehlt'], true), true);
$e = treatwellAusMail($m);
pruefe('mit HTML-Fassung: nichts fehlt', $e['fehlt'], []);
pruefe('mit HTML-Fassung: Name', $e['name'] !== '', true);

$z = twZustand($cfg);

pruefe('Buchung 1 schon von Hand eingetragen: nur verknüpft', twAnwenden($cfg, treatwellLesen($b1, $t1), $b1, $z), 'verknüpft (schon von Hand eingetragen)');
pruefe('… mit Bestellnummer im Termin', twTerminFinden($cfg, 'T2194729034')['termin']['id'] ?? null, 'hand1');
pruefe('Buchung 2 neu eingetragen', twAnwenden($cfg, treatwellLesen($b2, $t2), $b2, $z), 'eingetragen');
$t = twTerminFinden($cfg, 'T2195121742');
pruefe('… bei Andrea', $t['person'] ?? null, 'andrea');
pruefe('… Titel', $t['termin']['summary'] ?? null, 'Erika Beispiel – Damen – Trockenhaarschnitt (Treatwell)');
pruefe('… nicht bezahlt vermerkt', $t['termin']['extendedProperties']['private']['twbezahlt'] ?? null, 'Nicht bezahlt');
pruefe('… 25 Minuten', terminZeit($t['termin'])[1] - terminZeit($t['termin'])[0], 1500);
pruefe('Dieselbe E-Mail noch einmal: kein zweiter Termin', twAnwenden($cfg, treatwellLesen($b2, $t2), $b2, $z), 'aktualisiert');
pruefe('… weiterhin nur ein Termin bei Andrea', count(demoTermine($cfg, 'andrea')), 1);

// Überschneidung: andere Buchung zur selben Zeit bei Andrea
$e = treatwellLesen($b2, str_replace(['T2195121742', 'Erika Beispiel'], ['T2195999999', 'Paula Probe'], $t2));
pruefe('Überschneidung trotzdem eingetragen', twAnwenden($cfg, $e, $b2, $z), 'eingetragen');
pruefe('… und als Hinweis gemeldet', str_contains(end($z['hinweise'])['text'] ?? '', 'überschneidet sich'), true);

// Unvollständige E-Mail: nichts raten
$e = treatwellLesen($b2, str_replace('Datum/Zeit', 'Wann', $t2));
pruefe('Unlesbares Datum: Hinweis statt Termin', twAnwenden($cfg, $e, $b2, $z), 'Hinweis');

// Verschiebung einer bekannten Buchung: Termin wandert auf die neue Zeit
$e = treatwellLesen('TERMIN VERSCHOBEN: Buchung über Treatwell (Unsere Ref. T2195121742)', str_replace(['9 Oktober 2026 at 13:35', 'Kundenname'], ['16 Oktober 2026 at 09:00', 'Gastname'], $t2));
pruefe('Verschiebung bekannter Buchung', twAnwenden($cfg, $e, 'verschoben', $z), 'aktualisiert');
pruefe('… neue Zeit', date('Y-m-d H:i', terminZeit(twTerminFinden($cfg, 'T2195121742')['termin'])[0]), '2026-10-16 09:00');

// Verschiebung einer Buchung von vor dem Abruf: neu eintragen und Hinweis
$vorher = count($z['hinweise']);
pruefe('Verschiebung unbekannter Buchung wird eingetragen', twAnwenden($cfg, treatwellLesen($bv, $tv), $bv, $z), 'eingetragen');
pruefe('… mit Hinweis, alten Termin zu prüfen', count($z['hinweise']) > $vorher && str_contains(end($z['hinweise'])['text'], 'verschoben'), true);
pruefe('… Paketname als eigene Tätigkeit', twTerminFinden($cfg, 'T2192396359')['termin']['extendedProperties']['private']['taetigkeit'] ?? null, 'Paket - Ansatzfarbe, Waschen, Pflege, Schneiden & Föhnen');

// Stornierung
// Echte Storno-E-Mail: Betreff nur „STORNIERUNG“, Nummer als „Bestellreferenz:“ im Text
$ts = file_get_contents(__DIR__ . '/treatwell/storno.txt');
foreach (['Text' => $ts, 'HTML' => mailText(alsMail('STORNIERUNG', file_get_contents(__DIR__ . '/treatwell/storno.html')))['text']] as $weg => $x) {
    $e = treatwellLesen('STORNIERUNG', $x);
    pruefe("$weg Storno: Art", $e['art'], 'storno');
    pruefe("$weg Storno: Bestellnummer", $e['nr'], 'T2190000077');
}
pruefe('Stornierung löscht den Termin', twAnwenden($cfg, treatwellLesen('STORNIERUNG', str_replace('T2190000077', 'T2195121742', $ts)), 'STORNIERUNG', $z), 'storniert');
pruefe('… Termin ist weg', twTerminFinden($cfg, 'T2195121742'), null);
pruefe('Stornierung unbekannter Buchung: Hinweis', twAnwenden($cfg, treatwellLesen('Stornierte Treatwell Buchung (Unsere Ref. T2100000000)', ''), 'Storno', $z), 'Hinweis');

array_map('unlink', glob("$daten/*"));
rmdir($daten);
echo $fehler ? "\n$fehler Fehler\n" : "\nAlle Tests bestanden\n";
exit($fehler ? 1 : 0);
