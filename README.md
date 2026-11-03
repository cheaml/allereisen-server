# AlleReisen – Server

Selbst gehostete Website für Reisedokumentation: Reisen mit Karte, Stationen, Tagestexten, Fotos und Videos. Die Originalmedien bleiben in OneDrive oder Dropbox; der Server speichert Metadaten, Texte und Vorschaubilder.

Der Server ist die optionale gemeinsame Basis für die AlleReisen-Apps (iPhone/iPad und Windows). Die Apps funktionieren auch ohne Server mit lokalen Reisen. Mit eigenem Server kommen hinzu: Website-Ansicht im Browser, Gastzugänge, Leserechte für Familie und Freunde sowie Abgleich zwischen Geräten.

> English summary: self-hosted PHP/MySQL server for the AlleReisen travel journal apps. Photos and videos stay in the user's OneDrive or Dropbox; the server stores trip metadata, texts and previews. Documentation is in German. Licensed under AGPL-3.0.

## Funktionen

- Weltkarte aller Reisen, Reiseansicht mit Stationen, Tagesübersicht und Diashow
- Import aus OneDrive (persönliche Microsoft-Konten) und optional Dropbox; HEIC/HEIF und Videos
- Anmeldung mit Microsoft-Konto, Administratoren, Importrechte, Leserechte und Gastzugänge
- Besitzer bearbeiten ihre eigenen Reisen, Administratoren alle; App-Verwaltung (Konten, Rechte, Server) nur für Administratoren
- Konto selbst löschen (Website und Apps), inklusive eigener Reisen und Vorschaubilder
- Geräteanbindung für die iOS- und Windows-App (Lesen fremder Reisen, Übernahme eigener Reisen)
- Optional: Wetter der Reisetage, Fotoautomatik für OneDrive (standardmäßig aus)

## Voraussetzungen (Kurzfassung)

- PHP 8.3+ mit PDO/MySQL, cURL, OpenSSL, mbstring, EXIF, fileinfo; für HEIC Imagick mit libheif
- MySQL 8.0+ oder MariaDB 10.11+, Apache 2.4 mit `.htaccess` und HTTPS
- Eine **eigene App-Registrierung in Microsoft Entra** (Client-ID und Client-Secret) für die Anmeldung und den OneDrive-Zugriff

Ausführlich: [docs/INSTALLATION.md](docs/INSTALLATION.md).

## Schnellstart

1. Dateien auf das Hosting kopieren (ohne `docs/` und `tools/` im öffentlichen Webverzeichnis).
2. Leere Datenbank anlegen und `api/schema.sql` importieren.
3. `api/config.php.example` nach `api/config.php` kopieren und ausfüllen (Datenbank, `app_url`, `admin_emails`, Schlüssel, Microsoft-App).
4. `php tools/check-install.php` ausführen, dann `https://EIGENE-DOMAIN/login.html` öffnen.
5. Apps verbinden: [docs/APPS.md](docs/APPS.md).

Betrieb, Sicherung und Updates: [docs/OPERATIONS.md](docs/OPERATIONS.md). Fotoautomatik: [docs/FOTOAUTOMATIK.md](docs/FOTOAUTOMATIK.md).

## Datenschutz und Verantwortung

Wer einen Server betreibt, ist für die dort gespeicherten Daten seiner Nutzerinnen und Nutzer verantwortlich (Datenschutzhinweise, Aufbewahrung, Löschung). Der Server lädt keine Originalmedien von Geräten hoch. Externe Dienste (Karten, Wetter, Microsoft) sind in [docs/THIRD-PARTY.md](docs/THIRD-PARTY.md) beschrieben.

## Status

Entwicklungsvorschau. Im eigenen Familienbetrieb produktiv im Einsatz; eine Abnahme auf weiteren Hostingumgebungen steht aus. Updates einer bestehenden Installation erfordern gezielte Datenbankmigrationen – siehe [docs/OPERATIONS.md](docs/OPERATIONS.md).

## Lizenz

Der Programmcode steht unter der **GNU Affero General Public License v3.0** ([LICENSE](LICENSE)). Wer eine veränderte Fassung öffentlich als Website betreibt, muss deren Quellcode den Nutzern zugänglich machen.

Daten und Dienste Dritter (u. a. GeoNames, Natural Earth, OpenStreetMap, Esri, Open-Meteo) unterliegen ihren eigenen Bedingungen: [docs/THIRD-PARTY.md](docs/THIRD-PARTY.md).
