# Betrieb, Sicherung und Updates

Datenbank, geschützte `api/config.php` einschließlich Verschlüsselungsschlüssel sowie benötigte Cache-/Vorschaudaten zusammen sichern. Sicherungen verschlüsseln, getrennt vom Webroot speichern und Wiederherstellung testen. Originalmedien beim jeweiligen Anbieter gesondert sichern. Ohne den Verschlüsselungsschlüssel sind gespeicherte OAuth-Tokens nicht wiederherstellbar.

Vor einem Update Datenbank und installierte Programmversion sichern und den Dienst in ein Wartungsfenster setzen. Neue Version zuerst auf einer getrennten Testinstanz prüfen. `config.php` nicht durch die Vorlage ersetzen. Passende Schemaänderungen anhand des tatsächlich installierten Schemas auswählen: `schema.sql` ist nur für leere Datenbanken; `migration-*.sql` sind keine automatisch sortierte, beliebig wiederholbare Updatekette. Einige ändern Daten und benötigen eine vorherige Vorschau/Abnahme. Ein versionierter Migrationsrunner ist für eine spätere Verkaufsfreigabe noch erforderlich.

Dateien vollständig aktualisieren, dann die Installationsprüfung und Browserabnahme wiederholen. Bei einem fehlgeschlagenen Update zusammenpassenden Datenbankstand und Programmstand wiederherstellen. Programm-Rollback allein kann nach einer Schemaänderung unzureichend sein.

PHP-Sicherheitsupdates, OAuth-Secret-Abläufe, Speicherplatz und Logs regelmäßig prüfen. Bei Schlüssel-/Tokenverlust müssen Medienverbindungen neu autorisiert werden. Logs dürfen keine Zugangsdaten enthalten. Betreiber muss Aufbewahrung/Löschung, Datenschutzhinweise und externe Datenübermittlungen für seinen Einsatz festlegen.
