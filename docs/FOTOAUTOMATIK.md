# Allgemeine OneDrive-Fotoautomatik

Die optionale Automatik findet Reiseaufnahmen in einem selbst gewählten OneDrive-Ordner und seinen Unterordnern und fasst sie zu Reisen zusammen. **Deine Originale bleiben, wo sie sind:** Die Automatik kopiert, verschiebt, benennt und löscht keine Dateien. Sie prüft jedes Original (Version und SHA-256) und vermerkt im Reisekatalog nur, welche Datei zu welcher Reise gehört – wie der Import in den Apps. Sie benötigt einen eingerichteten Server, dessen Microsoft-Administrator Zugriff auf das persönliche OneDrive hat. Die normale Website und die Apps funktionieren auch ohne diese Automatik.

## Deine Einstellungen

| Einstellung | Vorschlag und Bedeutung |
|---|---|
| Überwachter Ordner | Kamera-Upload-Ordner, z. B. `Photos/Eigene Aufnahmen`; jeder eigene OneDrive-Ordner ist wählbar, z. B. `Bilder/Kamera`. Unterordner zählen mit. Ein leerer Pfad bezeichnet die eigene OneDrive-Wurzel. |
| Heimatpunkt | Eigene bestätigte Breiten-/Längengrade. Keine Adresse wird automatisch an einen Geocoder geschickt. |
| Mindestentfernung | 30 km Luftlinie; änderbar. Genau auf der Grenze zählt eine Aufnahme noch als Heimat. |
| Weitere Heimatorte | Optional bis zu 5 (auf der Seite 3), z. B. Zweitwohnsitz, Arbeitsort, Eltern. Im selben Radius zählen Aufnahmen wie zu Hause. |
| Stationsgröße | 1 km (Standard). 300 m für Städtereisen, 2 km für Wandern, 5 km für Roadtrips: Fotos desselben Tages in dieser Entfernung bilden eine Station. |
| Funkstille | Aus (Standard). Sonst endet eine Reise nach 2, 3, 5 oder 7 Tagen ohne Reisefoto; das nächste Reisefoto beginnt eine neue Reise. |
| Kurzer Heimataufenthalt | Aus (Standard). Sonst bleibt es eine Reise, wenn es nach höchstens 12, 24 oder 48 Stunden zu Hause weitergeht. |
| Kleinere Fotos | Aus (Standard): nur Kameraaufnahmen. Ein: auch kleinere Fotos und Fotos ohne Ortsangabe, z. B. aus WhatsApp (siehe unten). |
| Zeitzone | `Europe/Vienna`, änderbar; bestimmt die nächtliche Aufnahmegrenze. |

Die bisherige persönliche Automatik ist ein anderer Worker mit eigenen Regeln und separater Verbindung. Beide dürfen nicht gleichzeitig dieselben Quellen bearbeiten. Diese Anleitung richtet ausschließlich die allgemeine Automatik ein.

## So werden Reisen erkannt

- Maßgeblich sind Aufnahmezeit und Aufnahme-GPS aus den Originalmetadaten, niemals Upload- oder Dateiänderungszeit. Eine eindeutige Zeitzonenangabe ist erforderlich.
- Standardmäßig zählen nur Kameraaufnahmen: Fotos mit **mehr als 2.000 Pixeln an beiden Seiten**, Videos ab **1.920 × 1.080 Pixeln**, auch im Hochformat. Keine Beschränkung auf Apple/iPhone. Bildschirmaufnahmen werden immer ausgeschlossen.
- Mit der Option **„Auch kleinere Fotos und Fotos ohne Ortsangabe“** (`smallPhotos`) zählen auch kleinere Fotos und Videos, z. B. aus WhatsApp. Mit Ortsangabe werden sie wie Kameraaufnahmen eingeordnet. Ohne Ortsangabe kommen sie zu der Reise, die zu ihrer Aufnahmezeit läuft – bis 6 Stunden vor dem ersten oder nach dem letzten Reisefoto, nie über ein Heimatfoto hinweg. Liegt keine Reise in der Nähe, warten sie ohne Prüffall. Kleinere Fotos und Fotos ohne Ort beginnen oder beenden nie selbst eine Reise; ein weitergeleitetes Bild zu Hause ist kein Heimatbeleg. Eine Aufnahmezeit ist weiterhin nötig.
- **Empfehlung: Die Option ausgeschaltet lassen**, wenn viele WhatsApp-Bilder im Quellordner liegen. Was du während einer Reise geschickt bekommst, stammt oft von anderen Orten, hat aber keine Ortsangabe. Die Automatik kann es daher nicht von deinen Reisefotos unterscheiden, und es landet in der laufenden Reise. Einzelne passende Bilder fügst du besser von Hand hinzu. Falsch zugeordnete Bilder entfernst du in der Reise über „Reise bearbeiten“ → Bearbeitungsmodus → Station → „Aus Reise entfernen“ oder „In andere Reise verschieben“. Die Automatik fügt sie danach nicht wieder hinzu.
- Eine passende Aufnahme außerhalb des Heimatradius beginnt eine Reise. Der Ort mit den meisten Aufnahmen bestimmt den Reisetitel `Ort YYYY` (bei Gleichstand der frühere). Für die Benennung gilt das im Foto aufgezeichnete lokale Aufnahmedatum. Der Ortsname wird aus mitgelieferten Kartendaten bestimmt, ohne GPS an einen externen Dienst zu senden. Ohne geeigneten Ort lautet er `Reise`.
- Die Reise läuft über Tageswechsel, Monate und beliebig lange Aufnahmeunterbrechungen weiter. Erst eine Kameraaufnahme **innerhalb des Heimatradius** beendet sie. Videos werden wie Fotos berücksichtigt, wenn Zeit, GPS und Mindestauflösung vorliegen.
- Optional: Mit **Funkstille** endet eine Reise auch nach der gewählten Zahl von Tagen ohne Reisefoto. Mit **kurzem Heimataufenthalt** setzt ein Reisefoto innerhalb der gewählten Stunden nach dem ersten Heimatfoto die Reise fort. Geänderte Regeln wirken nur auf noch nicht zugeordnete Aufnahmen; bestehende Reisen werden nie nachträglich geteilt oder zusammengelegt.
- Die nächste passende Außenaufnahme beginnt eine neue Reise. Gleichnamige Reisen sind erlaubt; jede hat ihre eigene Kennung.
- Der Titel einer bereits angelegten Reise bleibt stabil. Einen Reisetitel kannst du in der Website ändern; Folgeimporte überschreiben ihn nicht.
- Fehlende GPS-Daten (ohne die Option oben), Aufnahmezeit, Auflösung oder mehrdeutige Metadaten ergeben einen Prüffall. Eine solche Aufnahme gilt nicht als nachgewiesenes Heimatfoto.
- Verspätete Uploads werden anhand ihrer Aufnahmezeit eingeordnet. Würde ein nachträgliches Heimatfoto bereits zugeordnete Reisen teilen, hält die Automatik die betroffenen Zuordnungen an und meldet einen Prüffall. Sie verschiebt keine bereits importierten Fotos heimlich in andere Reisen. Gleichzeitige widersprüchliche Heimat-/Außenaufnahmen sind ebenfalls Prüffälle.

## Installation durch den Websitebetreiber

1. Website und Datenbank sichern. Das Serverpaket enthält die optionalen PHP-Dateien, **keine** persönlichen Einstellungen, Microsoft-Tokens oder Cronjobs.
2. PHP-CLI mit PDO-MySQL, cURL, OpenSSL und Prozessausführung bereitstellen. ExifTool und Perl aus den offiziellen Distributionsquellen des Betreibers installieren und absolute Pfade notieren. Sie werden nicht als Binärpakete mitgeliefert. Originale werden zur Metadatenprüfung vorübergehend in einen privaten Ordner geladen und danach entfernt. Aktuelle Obergrenze: **256 MiB pro Original**. Größere Originale blockieren mit Prüfhinweis den betreffenden Scan; ausreichenden privaten Speicher vorsehen. Web- und CLI-PHP müssen dieselbe Anwendung und Konfiguration verwenden.
3. `docs/travel-automation.example.json` in einen **privaten Ordner außerhalb des Document Root** kopieren, z. B. `/home/USER/reise-private/settings.json`. Heimatkoordinaten, Konto, Ordner und Werkzeugpfade ersetzen. Unterordner mit `/` trennen, ohne führenden `/`. Platzhalter sind absichtlich noch nicht lauffähig. Dateirechte `0600`, privater Ordner `0700`. Die Datei darf nicht unter `public_html`, `wwwroot` oder `htdocs` liegen.
4. Mit absoluten, eigenen Pfaden prüfen:

```sh
php /PFAD/ZUR/WEBSITE/tools/travel-automation.php validate --config /PRIVAT/settings.json
```

5. In `api/config.php` den optionalen Eintrag `travel_automation_settings_file` auf den absoluten Pfad dieser privaten JSON-Datei setzen. Danach erscheint für Microsoft-Admins unter „Anmeldung & Verbindungen“ der Link „Fotoautomatik einstellen“. Die geschützte Seite lässt nur den zur Konfiguration gehörenden Kontoinhaber Fotoordner, Heimatpunkt, weitere Heimatorte, Radius, Stationsgröße, Funkstille, Heimataufenthalt, die Option für kleinere Fotos, Zeitzone, Nachholbeginn und die Aktivierung ändern. Ältere Einstellungsdateien mit `targetFolder`, `cleanupEnabled` oder `storageMode` werden weiter angenommen; diese Werte haben keine Wirkung mehr und entfallen beim nächsten Speichern. Werkzeugpfade und Kontoinhaber bleiben Betreiberkonfiguration. Die Seite startet keinen Job.
6. Als Datenbank-Installationskonto `api/migration-automation.sql` und `api/migration-travel-automation.sql` einmalig ausführen, oder mit entsprechendem CLI-Datenbankkonto den Modus `install-schema` verwenden. Nur zusätzliche Automatik-Tabellen werden angelegt; der laufende Web-Benutzer benötigt keine dauerhaften CREATE-/ALTER-Rechte. Andere historische Migrationen nicht pauschal erneut anwenden.
7. Als Microsoft-Admin deiner Website `https://DEINE-WEBSITE/api/automation-connect.php?scope=selected` öffnen und die separate OneDrive-Lesefreigabe erteilen. Die vorhandene Web-Callbackadresse `/api/callback.php` wird weiterverwendet. Microsoft erteilt nur Lesezugriff (`Files.Read`); die Automatik verwendet ausschließlich lesende Microsoft-Graph-Abrufe. Eine früher erteilte Lese-/Schreibfreigabe funktioniert weiter, bis du die Verbindung erneuerst. Der Worker prüft zusätzlich Konto, Drive, Elternketten und den ausgewählten Fotoordner. Fremde Drives und geteilte Verknüpfungen werden nicht verfolgt. Die neue Verbindung ersetzt weder normale Website-/App-Anmeldungen noch die persönliche Photos-Automatikverbindung.
8. `check` statt `validate` ausführen. Dieser Schritt prüft zusätzlich Datenbank, Verbindung und Quellordner. Er startet keine Fotojobs.

## Probelauf, Aktivierung und Nachholen

`dry-run` verwendet die vorhandene OneDrive-Leseverbindung des Microsoft-Admins. Dafür sind weder die separate Lesefreigabe der Automatik noch die neuen Automatik-Tabellen nötig. Der Lauf liest die Quellen und erstellt einen privaten Bericht, trägt jedoch nichts in den Reisekatalog ein:

```sh
php /PFAD/ZUR/WEBSITE/tools/travel-automation.php dry-run --config /PRIVAT/settings.json
```

Der Probelauf hat einen eigenen wiederaufnehmbaren Scanstand. Bei großen Archiven mehrmals ausführen, bis `scanComplete=true` erscheint. Der Bericht steht im privaten Unterordner `travel-v2-…/preview.md`, die maschinenlesbare Ausgabe in `preview-status.json`. Token-Erneuerung und privater Scanfortschritt werden gespeichert; „lesend“ bezieht sich auf Fotos und Reisekatalog. Einen begonnenen echten Lauf verändert der Probelauf nicht.

Standardmäßig werden ältere Aufnahmen **nicht nachträglich importiert**. Ohne `startAt` beginnt die echte Überwachung zum Zeitpunkt des ersten aktivierten `run`-Laufs. Für einen gewünschten zukünftigen Start kannst du `startAt` als ISO-Zeitpunkt mit Zeitzone festlegen. Zum bewussten Nachholen setze `backfillFrom`, z. B. `2026-10-01T00:00:00+02:00`, und starte zuerst den Probelauf. Auch beim Nachholen muss der gewählte Zeitraum geeignete Heimatfotos enthalten, um Reisegrenzen nachweisen zu können; ohne Heimatfoto bleibt es eine fortlaufende Reise.

Nach Prüfung `enabled=true` setzen. `run` legt den echten privaten Zustand an und bindet dessen Speicherort an das Konto. Wiederholungen verwenden dieselben Reise- und Dateizuordnungen. **Diesen Zustand nicht löschen, verschieben oder durch den Probelaufzustand ersetzen.** Ein zweiter Worker mit anderem Zustandsordner für dasselbe Konto wird abgewiesen. Geänderte Einstellungen wirken ab dem Zeitpunkt des nächsten Laufs auf künftig aufgenommene, noch unbekannte Dateien; bereits bekannte Ereignisse und angelegte Reisen werden nicht rückwirkend umgerechnet. Ein neuer expliziter `backfillFrom`-Wert erlaubt einen weiteren bewussten Nachhol-Lauf. Bereits laufende Jobs behalten ihren bisherigen Fotoordner.

Jede Zuordnung läuft so: Original mit seiner exakten Version lesen → Inhalt per SHA-256 prüfen → erneut prüfen, dass sich Version und Ordner nicht geändert haben → im Reisekatalog als Verweis eintragen. Unsichere Antworten oder Versionsänderungen ergeben einen Prüffall. Wird ein Original später in OneDrive verschoben oder umbenannt, bleibt es über seine Kennung auffindbar. Frühere Versionen der Automatik konnten in einen Zielordner kopieren; solche Kopien und bereits angelegte Reisen bleiben unverändert erhalten.

## Nächtlicher Betrieb und Kontrolle

Beispiel für einen bestehenden Hosting-Cronplan (alle fünf Minuten, absolute Pfade ersetzen):

```cron
*/5 * * * * /PFAD/ZU/php /PFAD/ZUR/WEBSITE/tools/travel-automation.php night --config /PRIVAT/settings.json >> /PRIVAT/cron.log 2>&1
```

Der Modus `night` startet nur zwischen 00:00 und 06:00 in der konfigurierten Zeitzone; außerhalb dieses Fensters beendet er sich ohne Datenbank- oder OneDrive-Zugriff. `run` bleibt für einen ausdrücklich gestarteten manuellen Import verfügbar.

Ein Lauf verarbeitet ausschließlich Aufnahmezeiten **vor dem heutigen Tagesbeginn** in deiner Zeitzone. Neue Vortagsaufnahmen werden damit ab Mitternacht berücksichtigt. Die Uhrzeit des Hosting-Cronservers ist unabhängig davon; der Worker berechnet die Grenze selbst einschließlich Sommer-/Winterzeit. Der Import kann sich bei großen Beständen über mehrere begrenzte Läufe verteilen. Erst ein vollständiger Scan darf Zuordnungen eintragen, damit die beliebige OneDrive-Ordnerreihenfolge keine Reisegrenzen erfindet. Ein Dateilimit und ein Zeitbudget begrenzen jeden Lauf; ein privates Lock verhindert parallele Ausführung.

**Auch tagsüber alle 10 Minuten** (Schalter auf der Automatikseite und in der App unter Einstellungen → Reise-Automatik): Dann arbeitet derselbe Cronjob auch tagsüber, höchstens alle 10 Minuten (nachts bei jedem Aufruf), und berücksichtigt auch heutige Aufnahmen bis 5 Minuten vor dem Lauf. So erscheinen Reisefotos schon während der Reise, etwa 5 bis 15 Minuten nachdem sie in OneDrive angekommen sind. Voraussetzung ist der Cronplan oben (`*/5` oder `*/10`). Jeder Lauf liest dabei den überwachten Ordner in OneDrive neu ein. Bei sehr großen Ordnern lieber einen engeren Ordner wählen (zum Beispiel nur das laufende Jahr) oder bei der nächtlichen Verarbeitung bleiben.

**Welche Aufnahmen zählen:** Die Automatikseite zeigt Auflösung und Kamera deiner zuletzt verarbeiteten Fotos und Videos. Die Untergrenze für Fotos (kürzere Seite) und Videos ist wählbar, auch genau die Größe deiner Kamera. Standard: Fotos über 2.000 px, Videos ab Full HD.

**Heimatort:** Er lässt sich auf der Automatikseite über die Ortssuche (OpenStreetMap, über deinen Server abgefragt) oder durch Tippen auf die Karte setzen. Gespeichert werden Ortsname und Koordinaten. Dasselbe gilt für die weiteren Heimatorte.

`status` zeigt den letzten echten Lauf. `journal.md` nennt geplante Reisen, offene Zuordnungen und Prüffälle; `status.json` enthält Fortschritt und Zähler. Vor jeder Importserie entsteht eine private Katalogsicherung. Normale Websitebackups, private Automatikzustände und Originalfotos trotzdem unabhängig sichern. Bei `discovery-blocked` erst den genannten Fehler beheben; ohne vollständigen Scan werden keine neuen Fotojobs ausgelöst. Änderungen an bereits bewerteten Originalversionen halten den Scan zur Prüfung an.

Zum Pausieren `enabled=false` setzen und gegebenenfalls den Cronjob deaktivieren. Das trennt weder OneDrive noch löscht es bestehende Reisen. Ein sofort laufender Vorgang muss zunächst enden. Keine Originale oder Zustandsdateien zur Fehlerbehebung löschen.

## Abnahme auf einem getrennten Testserver

1. Konfiguration prüfen; neue leere Installation mit den beiden Automatikmigrationen testen.
2. Probelauf mit ausdrücklichem Nachholbeginn und wenigen eigenen Testaufnahmen außerhalb/innerhalb des Heimatradius. Bericht muss zwei Reisen mit einem dazwischenliegenden Heimatfoto vorsehen.
3. Zuordnung echt ausführen; Originalnamen, Metadaten, Foto-/Videoanzeige, Tageszuordnung, Wiederholung und Neustart prüfen. Die Originale müssen unverändert am selben Ort liegen.
4. Optional mit „kleinere Fotos“ ein WhatsApp-Foto ohne Ort aus der Reisezeit testen: es erscheint in der Reise.
5. Neue Serverreise auf iOS/Windows übernehmen und eine lokale Bearbeitung mit dem Server abgleichen. Das Datum des Hintergrundabrufs auf iOS bestimmt das Betriebssystem.

Lokale synthetische Prüfungen ersetzen weder Microsoft-Einwilligung noch diese reale Abnahme.
