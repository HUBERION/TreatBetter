<?php
/* Treatwell-E-Mails aus dem Salon-Postfach lesen (IMAP über TLS, ohne PHP-Erweiterung „imap“).
   Das Postfach wird nur lesend geöffnet (EXAMINE): nichts wird als gelesen markiert, verschoben oder gelöscht.
   Abgerufen werden nur E-Mails, deren Absender „treatwell“ enthält. */

declare(strict_types=1);

final class PostfachFehler extends RuntimeException {}

final class Imap
{
    private $s;
    private int $nr = 0;

    public function __construct(string $host, int $port, int $timeout = 20)
    {
        $kontext = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $s = @stream_socket_client("ssl://$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $kontext);
        if (!$s) throw new PostfachFehler("Keine Verbindung zum Postfach ($errstr)");
        stream_set_timeout($s, $timeout);
        $this->s = $s;
        $gruss = fgets($s);
        if ($gruss === false || !str_starts_with($gruss, '* OK')) throw new PostfachFehler('Postfach antwortet nicht');
    }

    private static function zitat(string $s): string
    {
        return '"' . addcslashes($s, "\"\\") . '"';
    }

    /* Befehl senden und Antwort lesen. Liefert [Zeilen, Literale]; Literale ({n}-Blöcke) stehen getrennt. */
    public function befehl(string $befehl): array
    {
        $tag = 'T' . (++$this->nr);
        fwrite($this->s, "$tag $befehl\r\n");
        $zeilen = [];
        $literale = [];
        while (true) {
            $zeile = fgets($this->s);
            if ($zeile === false) throw new PostfachFehler('Verbindung zum Postfach abgebrochen');
            // Literal: Zeile endet mit {Länge}, danach kommen genau so viele Bytes
            if (preg_match('/\{(\d+)\}\r\n$/', $zeile, $m)) {
                $rest = (int) $m[1];
                $daten = '';
                while ($rest > 0) {
                    $teil = fread($this->s, min($rest, 65536));
                    if ($teil === false || $teil === '') throw new PostfachFehler('Verbindung zum Postfach abgebrochen');
                    $daten .= $teil;
                    $rest -= strlen($teil);
                }
                $literale[] = $daten;
                $zeilen[] = rtrim($zeile);
                continue;
            }
            if (str_starts_with($zeile, "$tag ")) {
                if (!preg_match("/^$tag OK/", $zeile)) throw new PostfachFehler('Postfach: ' . trim(substr($zeile, strlen($tag) + 1)));
                return [$zeilen, $literale];
            }
            $zeilen[] = rtrim($zeile);
        }
    }

    public function anmelden(string $benutzer, string $passwort): void
    {
        try {
            $this->befehl('LOGIN ' . self::zitat($benutzer) . ' ' . self::zitat($passwort));
        } catch (PostfachFehler $e) {
            throw new PostfachFehler('Anmeldung am Postfach fehlgeschlagen (Benutzer oder Passwort falsch?)');
        }
    }

    /* Posteingang nur lesend öffnen. */
    public function nurLesen(string $ordner = 'INBOX'): void
    {
        $this->befehl('EXAMINE ' . self::zitat($ordner));
    }

    /* UIDs der E-Mails seit $seit (Datum), deren Absender $von enthält. */
    public function suchen(string $von, int $seit): array
    {
        [$zeilen] = $this->befehl('UID SEARCH SINCE ' . date('j-M-Y', $seit) . ' FROM ' . self::zitat($von));
        foreach ($zeilen as $z) {
            if (str_starts_with($z, '* SEARCH')) return array_map('intval', array_filter(explode(' ', trim(substr($z, 8)))));
        }
        return [];
    }

    /* Komplette E-Mail (Rohtext) holen, ohne sie als gelesen zu markieren. */
    public function holen(int $uid): string
    {
        [, $literale] = $this->befehl("UID FETCH $uid (BODY.PEEK[])");
        return $literale[0] ?? '';
    }

    public function abmelden(): void
    {
        try { $this->befehl('LOGOUT'); } catch (Throwable $e) { /* egal */ }
        fclose($this->s);
    }
}

/* ---------- E-Mails zerlegen (MIME) ---------- */

function mimeKopf(string $roh): array
{
    $teile = preg_split("/\r?\n\r?\n/", $roh, 2);
    $kopf = preg_replace("/\r?\n[ \t]+/", ' ', $teile[0]);
    $felder = [];
    foreach (preg_split("/\r?\n/", $kopf) as $zeile) {
        if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $zeile, $m)) $felder[strtolower($m[1])] = $m[2];
    }
    return [$felder, $teile[1] ?? ''];
}

function mimeText(string $s): string
{
    return mb_decode_mimeheader($s);
}

/* Text aus einer E-Mail: bevorzugt text/plain, sonst text/html ohne Tags. */
function mailText(string $roh): array
{
    [$kopf, $inhalt] = mimeKopf($roh);
    $plain = null;
    $html = null;
    $teil = function (array $kopf, string $inhalt) use (&$teil, &$plain, &$html) {
        $typ = strtolower($kopf['content-type'] ?? 'text/plain');
        if (str_starts_with($typ, 'multipart/') && preg_match('/boundary="?([^";]+)"?/i', $kopf['content-type'], $m)) {
            foreach (explode('--' . $m[1], $inhalt) as $stueck) {
                if (trim($stueck) === '' || str_starts_with($stueck, '--')) continue;
                [$k, $i] = mimeKopf(ltrim($stueck, "\r\n"));
                $teil($k, $i);
            }
            return;
        }
        $kodierung = strtolower(trim($kopf['content-transfer-encoding'] ?? ''));
        if ($kodierung === 'base64') $inhalt = base64_decode($inhalt);
        elseif ($kodierung === 'quoted-printable') $inhalt = quoted_printable_decode($inhalt);
        $zeichensatz = preg_match('/charset="?([^";]+)"?/i', $kopf['content-type'] ?? '', $m) ? $m[1] : 'UTF-8';
        if (strtoupper($zeichensatz) !== 'UTF-8') $inhalt = @mb_convert_encoding($inhalt, 'UTF-8', $zeichensatz) ?: $inhalt;
        if (str_starts_with($typ, 'text/plain') && $plain === null) $plain = $inhalt;
        if (str_starts_with($typ, 'text/html') && $html === null) $html = $inhalt;
    };
    $teil($kopf, $inhalt);
    if ($plain === null && $html !== null) {
        $t = preg_replace('#<(style|script)[^>]*>.*?</\1>#si', '', $html);
        $t = preg_replace('#<(br|/p|/div|/tr|/h\d|/li)[^>]*>#i', "\n", $t);
        $t = preg_replace('#</t[dh]>#i', ' ', $t);
        $plain = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', (string) $plain);
    $text = preg_replace("/\n\s*\n+/", "\n", str_replace("\r", '', $text));
    return [
        'betreff' => mimeText($kopf['subject'] ?? ''),
        'von' => mimeText($kopf['from'] ?? ''),
        'datum' => strtotime($kopf['date'] ?? '') ?: time(),
        'id' => trim($kopf['message-id'] ?? '', '<> ') ?: md5($roh),
        'text' => trim($text),
    ];
}
