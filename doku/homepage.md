# Homepage des Salons

Eigene Visitenkarten-Website für Friseur Andrea Zechmeister im Ordner [`homepage/`](../homepage/). Unabhängig von der Buchungs-Testversion in `docs/`, aber im gleichen Farbschema und mit dem gleichen Logo.

## Inhalt

| Datei | Zweck |
|---|---|
| `index.html` | Startseite: Logo, Leistungen (ohne Preise und Dauer), Team, Öffnungszeiten, Kontakt und Anfahrt |
| `impressum.html` | Impressum nach ECG, UGB und Mediengesetz |
| `datenschutz.html` | Datenschutzerklärung (keine Cookies, kein Tracking) |
| `style.css` | alle Stile, Farben als CSS-Variablen in `:root` |
| `logo.jpg`, `favicon.svg` | Logo und Browser-Symbol, Kopien aus `docs/` |
| `.htaccess` | IONOS/Apache: Umleitung auf HTTPS, Zwischenspeicher, Sicherheits-Header |

Es gelten dieselben Regeln wie für `docs/` (siehe [AGENTS.md](../AGENTS.md)): kein Build, keine fremden Server, keine erfundenen Fakten. Alle Salondaten stammen aus [fachlogik.md](fachlogik.md#bekannte-daten-über-den-salon).

## Vor dem Livegang

- [ ] Gelb markierte Stellen in `impressum.html` und `datenschutz.html` mit dem Salon klären und ausfüllen (vollständiger Name, E-Mail, Gewerbebehörde, UID, Speicherdauer der Server-Protokolle). Datenschutz und Impressum am besten von der Wirtschaftskammer prüfen lassen.
- [ ] Den Buchungslink (zweimal in `index.html`, Kommentar `BUCHUNGSLINK`) zeigt derzeit auf das Treatwell-Profil. Sobald die eigene Buchung läuft, dorthin umstellen und `datenschutz.html` anpassen.
- [ ] `<meta name="robots" content="noindex, nofollow">` in allen drei Seiten entfernen, damit Google die Seite findet.

## Auf IONOS hochladen

Voraussetzung ist ein IONOS-Paket mit Webspace (z. B. „Webhosting“), nicht der Baukasten „MyWebsite“. Dort lassen sich keine eigenen Dateien hochladen.

1. Im IONOS-Kundenbereich unter **Hosting › SFTP & SSH** einen SFTP-Zugang anlegen bzw. die Zugangsdaten nachsehen (Server, Benutzer, Passwort).
2. Mit einem SFTP-Programm verbinden, zum Beispiel [WinSCP](https://winscp.net) oder [FileZilla](https://filezilla-project.org). Alternativ im Kundenbereich den **Webspace Explorer** im Browser verwenden.
3. Den **Inhalt** des Ordners `homepage/` in das Zielverzeichnis der Domain kopieren (also `index.html` direkt dort, nicht den Ordner `homepage` selbst). Die Datei `.htaccess` ist versteckt: im Programm die Anzeige versteckter Dateien einschalten und sie mitkopieren.
4. Unter **Domains & SSL** prüfen, dass die Domain auf dieses Verzeichnis zeigt und ein SSL-Zertifikat aktiv ist. Sonst führt die HTTPS-Umleitung in der `.htaccess` ins Leere.
5. Die Domain im Browser aufrufen, am Handy und am Computer durchklicken, Telefonnummern antippen.

Zugangsdaten zu IONOS gehören nicht ins Repository.

## Ansehen ohne Server

Die Seite braucht keinen Server: `homepage/index.html` lässt sich direkt im Browser öffnen. Auf GitHub Pages liegt sie zusätzlich unter https://huberion.github.io/TreatBetter/homepage/, sobald der Branch in `main` ist.
