<?php
/* Tests für die Berechnung freier Termine. Aufruf: php termin/tests/zeit-test.php */

declare(strict_types=1);

require_once __DIR__ . '/../lib/zeit.php';

$fehler = 0;
function pruefe(string $was, $ist, $soll): void
{
    global $fehler;
    if ($ist === $soll) { echo "ok   $was\n"; return; }
    $fehler++;
    echo "FEHLER $was\n  ist:  " . json_encode($ist, JSON_UNESCAPED_UNICODE) . "\n  soll: " . json_encode($soll, JSON_UNESCAPED_UNICODE) . "\n";
}
$zeiten = fn(array $ts) => array_map('hhmm', $ts);
$andrea = person('andrea');
$pamela = person('pamela');
// „Jetzt“ = Montag, 19.10.2026, 07:00
$jetzt = um('2026-10-19', '07:00');

// Feiertage
pruefe('Nationalfeiertag geschlossen', oeffnung('2026-10-26'), ['zu' => true, 'hinweis' => 'Nationalfeiertag']);
pruefe('Ostermontag 2027', feiertage(2027)['2027-03-29'] ?? null, 'Ostermontag');
pruefe('Fronleichnam 2026', feiertage(2026)['2026-06-04'] ?? null, 'Fronleichnam');
pruefe('Samstag geschlossen', oeffnung('2026-10-24')['zu'] ?? false, true);
pruefe('Heiliger Abend', oeffnung('2026-12-24'), ['von' => '08:30', 'bis' => '12:00', 'hinweis' => 'Heiliger Abend']);

// Vorlaufzeit 4 Stunden: Montag ab 11:00, Ladenschluss 13:30, 60 Min. -> letzte Startzeit 12:30
pruefe('Vorlauf 4 h, Montag, 60 Min.', $zeiten(freieZeiten($andrea, '2026-10-19', 60, [], $jetzt)), ['11:00', '11:15', '11:30', '11:45', '12:00', '12:15', '12:30']);

// Pamela muss bis 14:00 fertig sein (Dienstag, 60 Min. -> letzte Startzeit 13:00)
$di = freieZeiten($pamela, '2026-10-20', 60, [], $jetzt);
pruefe('Pamela letzte Startzeit Dienstag', hhmm(end($di)), '13:00');
pruefe('Pamela erste Startzeit Dienstag', hhmm($di[0]), '08:30');
// Andrea Dienstag bis Ladenschluss 19:00
$di = freieZeiten($andrea, '2026-10-20', 60, [], $jetzt);
pruefe('Andrea letzte Startzeit Dienstag', hhmm(end($di)), '18:00');
// Montag schließt um 13:30, Pamela also auch nur bis 13:30
$mo = freieZeiten($pamela, '2026-10-19', 30, [], $jetzt);
pruefe('Pamela Montag bis Ladenschluss', hhmm(end($mo)), '13:00');

// Belegter Termin 10:00–11:00 mit 5 Min. Puffer, 30-Min.-Leistung am Dienstag
$belegt = [[um('2026-10-20', '10:00'), um('2026-10-20', '11:00')]];
$di = $zeiten(freieZeiten($andrea, '2026-10-20', 30, $belegt, $jetzt));
pruefe('09:15 frei (endet 09:45, Puffer bis 09:50)', in_array('09:15', $di, true), true);
pruefe('09:30 belegt (Puffer)', in_array('09:30', $di, true), false);
pruefe('11:00 belegt (Puffer danach)', in_array('11:00', $di, true), false);
pruefe('11:15 frei', in_array('11:15', $di, true), true);

// Feiertag und Wochenende ohne Termine
pruefe('Nationalfeiertag keine Termine', freieZeiten($andrea, '2026-10-26', 30, [], $jetzt), []);
pruefe('Samstag keine Termine', freieZeiten($andrea, '2026-10-24', 30, [], $jetzt), []);
// Vergangenheit und Buchungshorizont 3 Monate
pruefe('Gestern keine Termine', freieZeiten($andrea, '2026-10-16', 30, [], $jetzt), []);
pruefe('Letzter Tag = +3 Monate', letzterTag($jetzt), '2027-01-19');
pruefe('19.01.2027 noch buchbar', count(freieZeiten($andrea, '2027-01-19', 30, [], $jetzt)) > 0, true);
pruefe('20.01.2027 nicht mehr buchbar', freieZeiten($andrea, '2027-01-20', 30, [], $jetzt), []);

// Sommerzeit-Umstellung: Montag 29.03.2027 (Ostermontag, zu) -> Dienstag 30.03.2027 normal
$jetztMaerz = um('2027-03-26', '12:00');
$di = freieZeiten($andrea, '2027-03-30', 30, [], $jetztMaerz);
pruefe('Nach Zeitumstellung erste Zeit 08:30', hhmm($di[0]), '08:30');

// Team: Uhrzeit -> wer frei ist
$team = freieZeitenTeam([$andrea, $pamela], '2026-10-20', 30, ['pamela' => [[um('2026-10-20', '08:30'), um('2026-10-20', '09:00')]]], $jetzt);
pruefe('08:30 nur Andrea frei', $team[um('2026-10-20', '08:30')] ?? null, ['andrea']);
pruefe('09:15 beide frei', $team[um('2026-10-20', '09:15')] ?? null, ['andrea', 'pamela']);
pruefe('15:00 nur Andrea (Pamela bis 14:00)', $team[um('2026-10-20', '15:00')] ?? null, ['andrea']);

echo $fehler ? "\n$fehler Fehler\n" : "\nAlle Tests bestanden\n";
exit($fehler ? 1 : 0);
