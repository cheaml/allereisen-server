# Installation

## Hosting-Anforderungen

- PHP 8.3 oder neuer aus einem noch unterstützten Zweig mit aktuellen Sicherheitsupdates. Geprüft: PHP 8.3.36 auf dem Hosting (einschließlich Automatik-, Metadaten- und Kopierprüfungen) und PHP 8.4.26 lokal. Weitere Haupt-/Nebenversionen benötigen eigene Abnahme. Supportzeiträume: https://www.php.net/supported-versions.php
- Erweiterungen: PDO/MySQL (`pdo_mysql`), cURL, OpenSSL, mbstring, EXIF, fileinfo, session und JSON.
- Für verlässliche HEIC/HEIF-Anzeige: Imagick mit ImageMagick und aktivem HEIC/HEIF-Decoder (libheif). Imagick allein genügt nicht. Originalabruf und Anzeige mit einem echten HEIC prüfen.
- MySQL 8.0+ oder MariaDB 10.11+ als Zielplattform, InnoDB und utf8mb4. Diese Versionsspanne ist eine Anforderung für die Produktabnahme; die vollständige Matrix ist noch nicht getestet.
- Apache 2.4 mit PHP/FPM, HTTPS, `mod_headers`, `mod_authz_core` und erlaubten `.htaccess`-Direktiven (`FileInfo`, `AuthConfig`, `Options`, `Indexes`). Bei Nginx müssen Schutzregeln entsprechend übertragen werden; es wird nicht automatisch durch `.htaccess` geschützt.
- Ausgangsverbindungen per HTTPS zu Microsoft sowie den in THIRD-PARTY beschriebenen Diensten, gültiger CA-Zertifikatspeicher.
- Startwerte: `memory_limit=256M`, Importlaufzeiten mindestens 120 Sekunden; FPM-/Proxy-Timeouts passend einstellen. Große Reisen können mehr Ressourcen benötigen. Kein Upload lokaler Originale vorgesehen.
- Schreibrechte für `api/cache` und PHP-Temp-Verzeichnis. Keine pauschalen 777-Rechte. PHP muss isolierte Sitzungsordner im Temp-Verzeichnis anlegen dürfen.
- Ausreichend Platz für Kartenassets, Vorschaubilder und Sicherungen. Die Originalmedien bleiben bei OneDrive/Dropbox.

## Neuinstallation

1. Eigene HTTPS-Domain/Subdomain vorbereiten. Für die erste Version Installation im Domain-Wurzelverzeichnis verwenden.
2. Paket entpacken. Webdateien inklusive aller `.htaccess`-Dateien und JSON/GZIP-Kartenassets hochladen. `docs`, `tools`, `package-manifest.json` und README müssen nicht öffentlich ausgeliefert werden; außerhalb des DocumentRoot aufbewahren.
3. Leere Datenbank mit utf8mb4 und eigenem Datenbankbenutzer anlegen. `api/schema.sql` über phpMyAdmin oder `mysql -u INSTALL_USER -p DATABASE < api/schema.sql` importieren. Nur die Neuinstallation benötigt CREATE/ALTER-Rechte; der Laufzeitbenutzer benötigt SELECT, INSERT, UPDATE und DELETE.
4. `api/config.php.example` nach `api/config.php` kopieren. DSN, Benutzer/Passwort, eigene HTTPS-`app_url` ohne abschließenden Slash und `admin_emails` setzen. Optional `home_location` mit Breiten- und Längengrad des Heimorts eintragen: Auf der Weltkarte steht der Reisename dann an der Station, die am weitesten vom Heimort entfernt ist. Ohne Eintrag wird die erste Station der jeweiligen Reise als Bezug verwendet.
5. `encryption_key` einmalig auf einen zufälligen 32-Byte-Schlüssel als 64 Hexzeichen setzen: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Schlüssel sicher sichern. Er darf nach dem Speichern von OAuth-Tokens nicht einfach ersetzt werden.
6. Microsoft-App registrieren, persönliche Microsoft-Konten für OneDrive berücksichtigen. In genau dieser App-Registrierung unter der Plattform **Web** die vollständige eigene HTTPS-Adresse mit `/api/callback.php` registrieren. Webanmeldung, separate OneDrive-Verbindung und App-Anmeldung am Server verwenden diesen gemeinsamen Callback; `onedrive.php` wird intern aufgerufen. Bei zusätzlichen Testdomains deren vollständige Callback-Adresse ergänzen und bestehende Rücksprungadressen erhalten. Delegierte Berechtigungen entsprechend den Anmeldeendpunkten: `openid profile offline_access User.Read Files.Read Mail.Send` (Mail.Send für Einladungen). Eigene Client-ID und Client-Secret eintragen, Secret-Ablauf überwachen.
7. Optional Dropbox-App: Redirect `/api/dropbox.php`, Lesezugriff auf Metadaten/Inhalte, Offlinezugriff. Eigene Client-ID und Secret eintragen.
8. Wetter, Karten und sonstige Dienste gemäß THIRD-PARTY für den beabsichtigten kommerziellen Betrieb einrichten. Wetter kann deaktiviert werden. Optional Gemini mit eigenem API-Key und freigegebenen Modellnamen konfigurieren.
9. `api/cache` mit Schreibrechten für PHP anlegen; die mitgelieferte `.htaccess` sperrt direkte Zugriffe. Die Vorlagen und SQL-Dateien nach Einrichtung außerhalb des Webroots aufbewahren.
10. Im Paketordner `php tools/check-install.php` ausführen. CLI-PHP und Web-PHP können verschiedene Erweiterungen haben; beide prüfen.
11. `https://EIGENE-DOMAIN/login.html` öffnen und mit einer in `admin_emails` eingetragenen Adresse anmelden. Kleine OneDrive-Testreise importieren und Texte bearbeiten.

## Abnahme

- Login/Logout und Administratorrechte funktionieren.
- Vorschau, Originalabruf und Diashow mit JPEG, HEIC und Video prüfen.
- Gast erhält nur freigegebene Reisen; ohne Anmeldung sind Medien nicht zugänglich.
- `/api/config.php`, `/api/schema.sql`, `/api/cache/` und eine konkret vorhandene Cachedatei liefern keinen vertraulichen Inhalt (403/404). Verzeichnislisting ist deaktiviert. Nicht nur Status prüfen: insbesondere config.php darf niemals als Quelltext ausgeliefert werden.
- HTTPS und eigene OAuth-Redirects funktionieren. PHP-Fehler werden protokolliert, nicht öffentlich angezeigt (`display_errors=Off`).
- Nach einem Browserneustart bleibt die Anmeldung gemäß Sitzungsregeln gültig.

Die Serverlösung liest keine lokalen Dateien vom Gerät eines Websitebesuchers. Die Verbindung von iOS und Windows ist in APPS.md beschrieben; lokale Medienreferenzen bleiben auf dem jeweiligen Gerät. Die Windows-PHP-Testlaufzeit ist nicht Teil dieses Serverpakets.

## Apps verbinden

Nach der Browser-Abnahme `APPS.md` lesen. Das aktuelle Schema enthält die Tabellen für Gerätefreigabe, Revisionsabgleich, Sicherungen und Schrittwerte. Bei Updates zuerst die erforderlichen Migrationen mit dem Installationskonto ausführen; der Web-Laufzeitbenutzer benötigt dafür keine CREATE-/ALTER-Rechte.
