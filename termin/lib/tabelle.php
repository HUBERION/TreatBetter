<?php
/* Excel-Dateien (.xlsx) und CSV lesen und schreiben, ohne Zusatzbibliotheken.
   Eine .xlsx ist ein Zip-Archiv mit XML-Dateien. Gelesen wird das erste Tabellenblatt,
   geschrieben wird ein einfaches Blatt mit fetter Kopfzeile. */

declare(strict_types=1);

/* ---------- Zip ---------- */

/* Dateien aus einem Zip-Archiv (Inhalt als Zeichenkette) holen: Name => Inhalt. */
function zipLesen(string $zip, array $nur = []): array
{
    $ende = strrpos($zip, "PK\x05\x06");
    if ($ende === false) throw new RuntimeException('Keine gültige Excel-Datei');
    $e = unpack('veintraege/Vsize/Voffset', substr($zip, $ende + 10, 10));
    $pos = $e['offset'];
    $out = [];
    for ($i = 0; $i < $e['eintraege']; $i++) {
        if (substr($zip, $pos, 4) !== "PK\x01\x02") throw new RuntimeException('Beschädigte Excel-Datei');
        $c = unpack('vmethode/vzeit/vdatum/Vcrc/Vgepackt/Vgroesse/vnamelen/vextralen/vkommlen/vdisk/vintattr/Vextattr/Vlokal', substr($zip, $pos + 10, 36));
        $name = substr($zip, $pos + 46, $c['namelen']);
        $pos += 46 + $c['namelen'] + $c['extralen'] + $c['kommlen'];
        if ($nur && !in_array($name, $nur, true)) continue;
        $l = unpack('vnamelen/vextralen', substr($zip, $c['lokal'] + 26, 4));
        $daten = substr($zip, $c['lokal'] + 30 + $l['namelen'] + $l['extralen'], $c['gepackt']);
        if ($c['methode'] === 8) $daten = gzinflate($daten);
        elseif ($c['methode'] !== 0) throw new RuntimeException('Nicht unterstützte Komprimierung');
        if ($daten === false) throw new RuntimeException('Beschädigte Excel-Datei');
        $out[$name] = $daten;
    }
    return $out;
}

/* Zip-Archiv erzeugen (komprimiert, falls zlib vorhanden). */
function zipSchreiben(array $dateien): string
{
    $zip = '';
    $verzeichnis = '';
    $zeit = (int) (date('H') << 11 | date('i') << 5 | intdiv((int) date('s'), 2));
    $datum = (int) ((date('Y') - 1980) << 9 | date('n') << 5 | date('j'));
    foreach ($dateien as $name => $inhalt) {
        $crc = crc32($inhalt);
        $gepackt = function_exists('gzdeflate') ? gzdeflate($inhalt, 6) : false;
        $methode = $gepackt === false ? 0 : 8;
        if ($gepackt === false) $gepackt = $inhalt;
        $kopf = pack('vvvvvVVVvv', 20, 0x0800, $methode, $zeit, $datum, $crc, strlen($gepackt), strlen($inhalt), strlen($name), 0);
        $offset = strlen($zip);
        $zip .= "PK\x03\x04" . $kopf . $name . $gepackt;
        $verzeichnis .= "PK\x01\x02" . pack('v', 20) . $kopf . pack('vvvVV', 0, 0, 0, 0, $offset) . $name;
    }
    return $zip . $verzeichnis . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($dateien), count($dateien), strlen($verzeichnis), strlen($zip), 0);
}

/* ---------- Excel (.xlsx) ---------- */

function spalteIndex(string $zelle): int
{
    preg_match('/^[A-Z]+/', $zelle, $m);
    $n = 0;
    foreach (str_split($m[0] ?? 'A') as $c) $n = $n * 26 + (ord($c) - 64);
    return $n - 1;
}

function xmlLaden(string $xml): SimpleXMLElement
{
    $alt = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
    libxml_use_internal_errors($alt);
    if ($x === false) throw new RuntimeException('Beschädigte Excel-Datei');
    return $x;
}

/* Erstes Tabellenblatt einer .xlsx als Liste von Zeilen (Liste von Zeichenketten). */
function xlsxLesen(string $inhalt): array
{
    $d = zipLesen($inhalt);
    $texte = [];
    if (isset($d['xl/sharedStrings.xml'])) {
        $ss = xmlLaden($d['xl/sharedStrings.xml']);
        foreach ($ss->si as $si) {
            $t = isset($si->t) ? (string) $si->t : '';
            foreach ($si->r as $r) $t .= (string) $r->t;
            $texte[] = $t;
        }
    }
    // Erstes Blatt über workbook.xml und die Verweise finden
    $blatt = 'xl/worksheets/sheet1.xml';
    if (isset($d['xl/workbook.xml'], $d['xl/_rels/workbook.xml.rels'])) {
        $wb = xmlLaden($d['xl/workbook.xml']);
        $rid = (string) ($wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
        foreach (xmlLaden($d['xl/_rels/workbook.xml.rels'])->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $ziel = ltrim((string) $rel['Target'], '/');
                $blatt = str_starts_with($ziel, 'xl/') ? $ziel : 'xl/' . $ziel;
            }
        }
    }
    if (!isset($d[$blatt])) throw new RuntimeException('Kein Tabellenblatt gefunden');
    $zeilen = [];
    foreach (xmlLaden($d[$blatt])->sheetData->row as $row) {
        $z = [];
        foreach ($row->c as $c) {
            $typ = (string) $c['t'];
            $wert = match ($typ) {
                's' => $texte[(int) $c->v] ?? '',
                'inlineStr' => (string) $c->is->t . implode('', array_map(fn($r) => (string) $r->t, iterator_to_array($c->is->r ?? [], false))),
                'b' => ((string) $c->v) === '1' ? 'WAHR' : 'FALSCH',
                default => (string) $c->v,
            };
            $z[spalteIndex((string) $c['r'])] = $wert;
        }
        if ($z) {
            $voll = array_fill(0, max(array_keys($z)) + 1, '');
            foreach ($z as $i => $v) $voll[$i] = $v;
            $zeilen[] = $voll;
        }
    }
    return $zeilen;
}

/* Einfache .xlsx mit einem Blatt: erste Zeile fett, alles als Text (keine Formeln). */
function xlsxSchreiben(array $zeilen, string $blattname = 'Kunden', array $breiten = []): string
{
    $x = fn(string $s) => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $spalte = function (int $i): string {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    };
    $rows = '';
    foreach (array_values($zeilen) as $r => $zeile) {
        $cells = '';
        foreach (array_values($zeile) as $c => $wert) {
            $cells .= '<c r="' . $spalte($c) . ($r + 1) . '" t="inlineStr"' . ($r === 0 ? ' s="1"' : '') . '><is><t xml:space="preserve">' . $x((string) $wert) . '</t></is></c>';
        }
        $rows .= '<row r="' . ($r + 1) . '">' . $cells . '</row>';
    }
    $cols = '';
    foreach ($breiten as $i => $b) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $b . '" customWidth="1"/>';
    $ns = 'http://schemas.openxmlformats.org';
    return zipSchreiben([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="' . $ns . '/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' . $ns . '/package/2006/relationships"><Relationship Id="rId1" Type="' . $ns . '/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="' . $ns . '/spreadsheetml/2006/main" xmlns:r="' . $ns . '/officeDocument/2006/relationships"><sheets><sheet name="' . $x($blattname) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' . $ns . '/package/2006/relationships"><Relationship Id="rId1" Type="' . $ns . '/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="' . $ns . '/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="' . $ns . '/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="' . $ns . '/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' . ($cols ? '<cols>' . $cols . '</cols>' : '') . '<sheetData>' . $rows . '</sheetData></worksheet>',
    ]);
}

/* ---------- CSV ---------- */

function csvLesen(string $inhalt): array
{
    if (str_starts_with($inhalt, "\xEF\xBB\xBF")) $inhalt = substr($inhalt, 3);
    elseif (!mb_check_encoding($inhalt, 'UTF-8')) $inhalt = mb_convert_encoding($inhalt, 'UTF-8', 'Windows-1252');
    $erste = strtok($inhalt, "\n") ?: '';
    $trenner = substr_count($erste, ';') >= substr_count($erste, ',') ? (substr_count($erste, "\t") > substr_count($erste, ';') ? "\t" : ';') : ',';
    $f = fopen('php://memory', 'r+');
    fwrite($f, $inhalt);
    rewind($f);
    $zeilen = [];
    while (($z = fgetcsv($f, 0, $trenner, '"', '')) !== false) {
        if ($z !== [null]) $zeilen[] = array_map(fn($v) => (string) $v, $z);
    }
    fclose($f);
    return $zeilen;
}

/* Hochgeladene Tabelle lesen: .xlsx oder .csv. */
function tabelleLesen(string $inhalt, string $dateiname): array
{
    if (str_starts_with($inhalt, "PK\x03\x04")) return xlsxLesen($inhalt);
    if (preg_match('/\.xls$/i', $dateiname)) throw new RuntimeException('Das alte Excel-Format (.xls) geht leider nicht. Bitte in Excel „Speichern unter“ › „Excel-Arbeitsmappe (.xlsx)“ wählen.');
    return csvLesen($inhalt);
}
