# Allgemeine OneDrive-Fotoautomatik

Die optionale Automatik findet Reiseaufnahmen in einem selbst gewählten OneDrive-Ordner und seinen Unterordnern. Sie benötigt einen eingerichteten Server, dessen Microsoft-Administrator Zugriff auf das persönliche OneDrive hat. Die normale Website und die Apps funktionieren auch ohne diese Automatik.

## Deine Einstellungen

| Einstellung | Vorschlag und Bedeutung |
|---|---|
| Überwachter Ordner | Kamera-Upload-Ordner, z. B. `Photos/Eigene Aufnahmen`; jeder eigene OneDrive-Ordner ist wählbar, z. B. `Bilder/Kamera`. Unterordner zählen mit. Ein leerer Pfad bezeichnet die eigene OneDrive-Wurzel. |
| Heimatpunkt | Eigene bestätigte Breiten-/Längengrade. Keine Adresse wird automatisch an einen Geocoder geschickt. |
| Mindestentfernung | 30 km Luftlinie; änderbar. Genau auf der Grenze zählt eine Aufnahme noch als Heimat. |
| Zielordner | `Reisen`, änderbar, z. B. `Bilder/Urlaubsreisen`. Dieser Ordner und bereits verwendete Automatikziele werden nicht überwacht. Der Quellordner darf nicht innerhalb eines Zielordners liegen. |
| Zeitzone | `Europe/Vienna`, änderbar; bestimmt die nächtliche Aufnahmegrenze. |

Die bisherige persönliche Automatik ist ein anderer Worker mit eigenen Regeln und separater Verbindung. Beide dürfen nicht gleichzeitig dieselben Quellen bearbeiten. Diese Anleitung richtet ausschließlich die allgemeine Automatik ein.

## So werden Reisen erkannt

- Maßgeblich sind Aufnahmezeit und Aufnahme-GPS aus den Originalmetadaten, niemals Upload- oder Dateiänderungszeit. Eine eindeutige Zeitzonenangabe ist erforderlich.
- Fotos benötigen **mehr als 2.000 Pixel an beiden Seiten**. Videos benötigen mindestens **1.920 × 1.080 Pixel**, auch im Hochformat. Keine Beschränkung auf Apple/iPhone. Bildschirmaufnahmen werden ausgeschlossen.
- Eine passende Aufnahme außerhalb des Heimatradius beginnt eine Reise. Der erste erkannte Aufnahmeort bestimmt zunächst Unterordner `Ort YYYY-MM` und Reisetitel `Ort YYYY`. Für die Benennung gilt das im Foto aufgezeichnete lokale Aufnahmedatum. Der Ortsname wird aus mitgelieferten Kartendaten bestimmt, ohne GPS an einen externen Dienst zu senden. Ohne geeigneten Ort lautet er `Reise`.
- Die Reise läuft über Tageswechsel, Monate und beliebig lange Aufnahmeunterbrechungen weiter. Erst eine Aufnahme **innerhalb des Heimatradius**, die ebenfalls ihre Mindestauflösung erfüllt, beendet sie. Diese Aufnahme bleibt im Quellordner. Videos werden wie die ebenfalls aufgenommenen Fotos berücksichtigt, wenn Zeit, GPS und Mindestauflösung vorliegen.
- Die nächste passende Außenaufnahme beginnt eine neue Reise. Bei gleichem Ort/Monat entstehen `Ort YYYY-MM (2)`, `(3)` usw.; vorhandene fremde Ordner werden nicht übernommen oder überschrieben.
- Titel und Ordnername einer bereits angelegten Reise bleiben stabil. Einen Reisetitel kannst du in der Website ändern; Folgeimporte überschreiben ihn nicht.
- Fehlende GPS-Daten, Aufnahmezeit, Auflösung oder mehrdeutige Metadaten ergeben einen Prüffall. Eine solche Aufnahme gilt nicht als nachgewiesenes Heimatfoto.
- Verspätete Uploads werden anhand ihrer Aufnahmezeit eingeordnet. Würde ein nachträgliches Heimatfoto bereits zugeordnete Reisen teilen, hält die Automatik betroffene Übertragungen und Quellenbereinigungen an und meldet einen Prüffall. Sie verschiebt keine bereits importierten Fotos heimlich in andere Reisen. Gleichzeitige widersprüchliche Heimat-/Außenaufnahmen sind ebenfalls Prüffälle.

## Installation durch den Websitebetreiber

1. Website und Datenbank sichern. Das Serverpaket enthält die optionalen PHP-Dateien, **keine** persönlichen Einstellungen, Microsoft-Tokens oder Cronjobs.
2. PHP-CLI mit PDO-MySQL, cURL, OpenSSL und Prozessausführung bereitstellen. ExifTool und Perl aus den offiziellen Distributionsquellen des Betreibers installieren und absolute Pfade notieren. Sie werden nicht als Binärpakete mitgeliefert. Originale werden zur Metadatenprüfung vorübergehend in einen privaten Ordner geladen und danach entfernt. Aktuelle Obergrenze: **256 MiB pro Original**. Größere Originale blockieren mit Prüfhinweis den betreffenden Scan; ausreichenden privaten Speicher vorsehen. Web- und CLI-PHP müssen dieselbe Anwendung und Konfiguration verwenden.
3. `docs/travel-automation.example.json` in einen **privaten Ordner außerhalb des Document Root** kopieren, z. B. `/home/USER/reise-private/settings.json`. Heimatkoordinaten, Konto, Ordner und Werkzeugpfade ersetzen. Unterordner mit `/` trennen, ohne führenden `/`. Platzhalter sind absichtlich noch nicht lauffähig. Dateirechte `0600`, privater Ordner `0700`. Die Datei darf nicht unter `public_html`, `wwwroot` oder `htdocs` liegen.
4. Mit absoluten, eigenen Pfaden prüfen:

```sh
php /PFAD/ZUR/WEBSITE/tools/travel-automation.php validate --config /PRIVAT/settings.json
```

5. In `api/config.php` den optionalen Eintrag `travel_automation_settings_file` auf den absoluten Pfad dieser privaten JSON-Datei setzen. Danach erscheint für Microsoft-Admins unter „Anmeldung & Verbindungen“ der Link „Fotoautomatik einstellen“. Die geschützte Seite lässt nur den zur Konfiguration gehörenden Kontoinhaber Quell-/Zielordner, Heimatpunkt, Radius, Zeitzone, Nachholbeginn und die beiden Aktivierungsschalter ändern. Werkzeugpfade und Kontoinhaber bleiben Betreiberkonfiguration. Die Seite startet keinen Job.
6. Als Datenbank-Installationskonto `api/migration-automation.sql` und `api/migration-travel-automation.sql` einmalig ausführen, oder mit entsprechendem CLI-Datenbankkonto den Modus `install-schema` verwenden. Nur zusätzliche Automatik-Tabellen werden angelegt; der laufende Web-Benutzer benötigt keine dauerhaften CREATE-/ALTER-Rechte. Andere historische Migrationen nicht pauschal erneut anwenden.
7. Als Microsoft-Admin deiner Website `https://DEINE-WEBSITE/api/automation-connect.php?scope=selected` öffnen und die separate OneDrive-Schreibfreigabe erteilen. Die vorhandene Web-Callbackadresse `/api/callback.php` wird weiterverwendet. Microsoft erteilt `Files.ReadWrite` für das Konto; der Worker prüft zusätzlich Konto, Drive, Elternketten sowie die ausgewählten Quell-/Zielordner. Fremde Drives und geteilte Verknüpfungen werden nicht verfolgt. Die neue Verbindung ersetzt weder normale Website-/App-Anmeldungen noch die persönliche Photos-Automatikverbindung.
8. `check` statt `validate` ausführen. Dieser Schritt prüft zusätzlich Datenbank, Verbindung und Quellordner. Er startet keine Fotojobs und erstellt keine Reiseordner.

## Probelauf, Aktivierung und Nachholen

`dry-run` verwendet die vorhandene OneDrive-Leseverbindung des Microsoft-Admins. Dafür sind weder die separate Schreibfreigabe noch die neuen Automatik-Tabellen nötig. Der Lauf liest die Quellen und erstellt einen privaten Bericht, kopiert und importiert jedoch keine Originale und bereinigt keine Quellen:

```sh
php /PFAD/ZUR/WEBSITE/tools/travel-automation.php dry-run --config /PRIVAT/settings.json
```

Der Probelauf hat einen eigenen wiederaufnehmbaren Scanstand. Bei großen Archiven mehrmals ausführen, bis `scanComplete=true` erscheint. Der Bericht steht im privaten Unterordner `travel-v2-…/preview.md`, die maschinenlesbare Ausgabe in `preview-status.json`. Token-Erneuerung und privater Scanfortschritt werden gespeichert; „lesend“ bezieht sich auf Fotos, Reiseordner und Reisekatalog. Einen begonnenen echten Lauf verändert der Probelauf nicht.

Standardmäßig werden ältere Aufnahmen **nicht nachträglich importiert**. Ohne `startAt` beginnt die echte Überwachung zum Zeitpunkt des ersten aktivierten `run`-Laufs. Für einen gewünschten zukünftigen Start kannst du `startAt` als ISO-Zeitpunkt mit Zeitzone festlegen. Zum bewussten Nachholen setze `backfillFrom`, z. B. `2026-10-01T00:00:00+02:00`, und starte zuerst den Probelauf. Auch beim Nachholen muss der gewählte Zeitraum geeignete Heimatfotos enthalten, um Reisegrenzen nachweisen zu können; ohne Heimatfoto bleibt es eine fortlaufende Reise.

Nach Prüfung `enabled=true`, aber zunächst `cleanupEnabled=false` setzen. `run` legt den echten privaten Zustand an und bindet dessen Speicherort an das Konto. Wiederholungen verwenden dieselben Reise- und Dateizuordnungen. **Diesen Zustand nicht löschen, verschieben oder durch den Probelaufzustand ersetzen.** Ein zweiter Worker mit anderem Zustandsordner für dasselbe Konto wird abgewiesen. Geänderte Einstellungen wirken ab dem Zeitpunkt des nächsten Laufs auf künftig aufgenommene, noch unbekannte Dateien; bereits bekannte Ereignisse und angelegte Reisen werden nicht rückwirkend umgerechnet. Ein neuer expliziter `backfillFrom`-Wert erlaubt einen weiteren bewussten Nachhol-Lauf. Bereits laufende Jobs behalten ihre bisherigen freigegebenen Quell-/Zielordner.

Erst nach einem begrenzten Kopier-/Importtest `cleanupEnabled=true` setzen, falls die Originale aus dem überwachten Ordner entfernt werden sollen. Die Reihenfolge ist immer: kopieren → Original und Kopie vollständig per SHA-256 prüfen → Katalogimport und Medienreferenzen bestätigen → Quelle mit ihrer exakten Version in den **OneDrive-Papierkorb** verschieben. Unsichere Antworten oder Versionsänderungen ergeben einen Prüffall und keinen blinden neuen Löschversuch. Heimataufnahmen werden nicht kopiert oder bereinigt.

## Nächtlicher Betrieb und Kontrolle

Beispiel für einen bestehenden Hosting-Cronplan (alle fünf Minuten, absolute Pfade ersetzen):

```cron
*/5 * * * * /PFAD/ZU/php /PFAD/ZUR/WEBSITE/tools/travel-automation.php night --config /PRIVAT/settings.json >> /PRIVAT/cron.log 2>&1
```

Der Modus `night` startet nur zwischen 00:00 und 06:00 in der konfigurierten Zeitzone; außerhalb dieses Fensters beendet er sich ohne Datenbank- oder OneDrive-Zugriff. `run` bleibt für einen ausdrücklich gestarteten manuellen Import verfügbar.

Ein Lauf verarbeitet ausschließlich Aufnahmezeiten **vor dem heutigen Tagesbeginn** in deiner Zeitzone. Neue Vortagsaufnahmen werden damit ab Mitternacht berücksichtigt. Die Uhrzeit des Hosting-Cronservers ist unabhängig davon; der Worker berechnet die Grenze selbst einschließlich Sommer-/Winterzeit. Der Import kann sich bei großen Beständen über mehrere begrenzte Läufe verteilen. Erst ein vollständiger Scan darf Kopier-/Importjobs ausführen, damit die beliebige OneDrive-Ordnerreihenfolge keine Reisegrenzen erfindet. Ein Dateilimit und ein Zeitbudget begrenzen jeden Lauf; ein privates Lock verhindert parallele Ausführung.

`status` zeigt den letzten echten Lauf. `journal.md` nennt geplante Zielordner, offene Jobs und Prüffälle; `status.json` enthält Fortschritt und Zähler. Vor jeder Importserie entsteht eine private Katalogsicherung. Normale Websitebackups, private Automatikzustände und Originalfotos trotzdem unabhängig sichern. Bei `discovery-blocked` erst den genannten Fehler beheben; ohne vollständigen Scan werden keine neuen Fotojobs ausgelöst. Änderungen an bereits bewerteten Originalversionen halten den Scan zur Prüfung an.

Zum Pausieren `enabled=false` setzen und gegebenenfalls den Cronjob deaktivieren. Das trennt weder OneDrive noch löscht es bestehende Reisen. Ein sofort laufender Vorgang muss zunächst enden. Keine Originale oder Zustandsdateien zur Fehlerbehebung löschen.

## Abnahme auf einem getrennten Testserver

1. Konfiguration prüfen; neue leere Installation mit den beiden Automatikmigrationen testen.
2. Probelauf mit ausdrücklichem Nachholbeginn und wenigen eigenen Testaufnahmen außerhalb/innerhalb des Heimatradius. Bericht muss zwei Reisen mit einem dazwischenliegenden Heimatfoto vorsehen.
3. Kopieren/Importieren ohne Bereinigung; Originalnamen, Metadaten, Foto-/Videoanzeige, Tageszuordnung, Wiederholung und Neustart prüfen.
4. Erst anschließend Quellenbereinigung für diese begrenzte Testauswahl prüfen; Heimatfoto bleibt erhalten.
5. Neue Serverreise auf iOS/Windows übernehmen und eine lokale Bearbeitung mit dem Server abgleichen. Das Datum des Hintergrundabrufs auf iOS bestimmt das Betriebssystem.

Lokale synthetische Prüfungen ersetzen weder Microsoft-Einwilligung noch diese reale Abnahme.
