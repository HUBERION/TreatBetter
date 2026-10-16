<?php
/* Stammdaten und Buchungsregeln des Salons.
   Quelle: Treatwell-Profil (Leistungen, Dauer, Stand 15.10.2026) und Angaben des Salons. */

declare(strict_types=1);

const SALON = [
    'name'   => 'Friseur Andrea Zechmeister',
    'strasse' => 'August-Jaksch-Straße 1',
    'ort'    => '9020 Klagenfurt',
    'telefon' => [['0463 51 59 52', '+43463515952'], ['0676 460 48 83', '+436764604883']],
    'mail'   => 'friseur@andreazechmeister.at',
];

/* Leistungen in derselben Reihenfolge und Gruppierung wie auf der Homepage.
   Die Dauer wird nur intern für die Berechnung verwendet und nicht angezeigt. */
const LEISTUNGEN = [
    ['id' => 'damen-wl',     'gruppe' => 'Damen', 'name' => 'Waschen & Legen', 'min' => 30],
    ['id' => 'damen-wf',     'gruppe' => 'Damen', 'name' => 'Waschen & Föhnen', 'min' => 30],
    ['id' => 'damen-wsf',    'gruppe' => 'Damen', 'name' => 'Waschen, Schneiden & Föhnen', 'min' => 60],
    ['id' => 'damen-trocken', 'gruppe' => 'Damen', 'name' => 'Trockenhaarschnitt', 'min' => 25],
    ['id' => 'herren-trocken', 'gruppe' => 'Herren', 'name' => 'Trockenhaarschnitt', 'min' => 20],
    ['id' => 'herren-wst',   'gruppe' => 'Herren', 'name' => 'Waschen, Schneiden & Trocknen', 'min' => 30],
    ['id' => 'kinder-10',    'gruppe' => 'Kinder, Teenager & Studierende', 'name' => 'Kinder bis 10 Jahre – Haarschnitt', 'min' => 20],
    ['id' => 'kinder-15',    'gruppe' => 'Kinder, Teenager & Studierende', 'name' => 'Kinder bis 15 Jahre – Haarschnitt', 'min' => 20],
    ['id' => 'teen-wsf',     'gruppe' => 'Kinder, Teenager & Studierende', 'name' => 'Teenager bis 16 Jahre – Waschen, Schneiden & Föhnen', 'min' => 45],
    ['id' => 'stud-wsf',     'gruppe' => 'Kinder, Teenager & Studierende', 'name' => 'Studierende – Waschen, Schneiden & Föhnen', 'min' => 45],
    ['id' => 'lotion',       'gruppe' => 'Haarkuren & Pflege', 'name' => 'Pflege-Lotion', 'min' => 5],
    ['id' => 'maske',        'gruppe' => 'Haarkuren & Pflege', 'name' => 'Haarmaske', 'min' => 15],
    ['id' => 'pflanzenfarbe', 'gruppe' => 'Culum Natura – Pflanzenfarbe', 'name' => 'Pflanzenfarbe – Beratungsgespräch', 'min' => 30],
    ['id' => 'verlaengerung', 'gruppe' => 'Haarverlängerung', 'name' => 'Haarverlängerung – Beratung', 'min' => 30],
];

/* Reihenfolge = Vorrang bei Gleichstand, wenn „Beliebig“ gewählt wird.
   bis: spätestes Terminende (null = Ladenschluss). Kalender-IDs stehen in der Konfiguration. */
const TEAM = [
    ['id' => 'andrea', 'name' => 'Andrea', 'rolle' => 'Inhaberin', 'tage' => [1, 2, 3, 4, 5], 'bis' => null],
    ['id' => 'pamela', 'name' => 'Pamela', 'rolle' => 'Friseurin', 'tage' => [1, 2, 3, 4, 5], 'bis' => '14:00'],
];

/* Öffnungszeiten je Wochentag (0 = Sonntag). */
const OEFFNUNG = [
    1 => ['08:30', '13:30'],
    2 => ['08:30', '19:00'],
    3 => ['08:30', '13:30'],
    4 => ['08:30', '19:00'],
    5 => ['07:30', '20:00'],
    6 => null,
    0 => null,
];

const REGELN = [
    'raster'    => 15,   // Minuten zwischen möglichen Startzeiten
    'puffer'    => 5,    // Minuten frei nach (und vor) jedem belegten Termin
    'vorlauf'   => 240,  // frühestens 4 Stunden ab jetzt
    'monate'    => 3,    // bis 3 Monate im Voraus
    'storno'    => 24,   // kostenlos stornierbar bis 24 Stunden vorher
    'aufbewahrung' => 12, // Monate: danach Name, E-Mail und Telefon aus vergangenen Terminen entfernen
];

function leistung(string $id): ?array
{
    foreach (LEISTUNGEN as $l) {
        if ($l['id'] === $id) return $l;
    }
    return null;
}

function person(string $id): ?array
{
    foreach (TEAM as $p) {
        if ($p['id'] === $id) return $p;
    }
    return null;
}

/* Bezeichnung der Leistung eines Termins aus seinen Zusatzfeldern:
   Leistung aus der Liste oder eine im Dashboard selbst benannte Tätigkeit. */
function leistungAusTermin(array $priv): string
{
    $l = leistung((string) ($priv['leistung'] ?? ''));
    return $l ? leistungsname($l) : (string) ($priv['taetigkeit'] ?? '');
}

/* Voller Name einer Leistung, z. B. „Damen – Trockenhaarschnitt“. */
function leistungsname(array $l): string
{
    return in_array($l['gruppe'], ['Damen', 'Herren'], true) ? $l['gruppe'] . ' – ' . $l['name'] : $l['name'];
}
