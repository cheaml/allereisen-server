# iOS und Windows mit einem eigenen Server

Dieser Server ist optional. Die Apps werden vom Nutzer mit einer eigenen HTTPS-Website verbunden. Der Herausgeber betreibt hierfür keinen allgemeinen Serverdienst.

## Voraussetzungen

1. Installation gemäß INSTALLATION.md im Domain-Wurzelverzeichnis, gültiges HTTPS-Zertifikat. Keine Unterordnerinstallation, Zugangsdaten in der URL oder Weiterleitung auf eine andere Domain.
2. Aktuelles Neuinstallationsschema oder gezielt eingespielte Migrationen. Vor einem Update Datenbank und Verschlüsselungsschlüssel sichern. `api/migration-device-sync.sql` vor der neuen Geräte-API installieren. Für ältere Installationen außerdem Importrechte, Publikationsstatus und manuelle Reisedaten prüfen. Nicht pauschal alle historischen Migrationen erneut ausführen.
3. Microsoft-Web-App mit den eigenen Redirect-URLs und zentralem Administrator wie in INSTALLATION.md. Das App-Gerät wird über die Website ausdrücklich autorisiert. Geräteverbindungen laufen nach 30 Tagen ab und können erneuert oder getrennt werden.
4. App und Server müssen Zugriff auf dieselben OneDrive-Originale und dasselbe Quellenkonto haben. App und Server melden sich unabhängig an. Es werden keine Microsoft-App-Tokens auf den Server exportiert.
5. Für die Geräteverbindung ist derzeit eine Microsoft-Adminrolle am Server erforderlich. Web-Importrechte allein reichen nicht. Nur konfigurierte Hauptadmins verwalten weitere Admins und Importrechte und geben Fremdimporte frei.
6. Webserver/FastCGI muss den Authorization-Header an PHP weiterreichen. Die Serverprüfung in der App darf weder auf eine Loginseite umgeleitet werden noch HTML anstelle von JSON liefern.

Die App prüft nach dem Verbinden Protokoll, installierte Abgleichtabellen und Verwaltungsfunktionen. Eine gespeicherte OneDrive-Verbindung ist noch kein Nachweis, dass ein abgelaufenes Token erneuert werden kann; die tatsächliche Quellenprüfung erfolgt zusätzlich vor einer Übertragung.

## Einrichtung in der App

Anmeldung & Verbindungen → Eigener Server → HTTPS-Adresse speichern → Server verbinden → Microsoft-Anmeldung am eigenen Server abschließen → Serverbedingungen prüfen.

Erstübernahme:

- iPhone zuerst: „Alle OneDrive-Reisen zum Server übertragen“. Die Bestätigung umfasst auch vorhandene Schrittwerte. Bereits vorhandene, abweichende Zielreisen werden nicht überschrieben, sondern zum Vergleich gestellt.
- Server zuerst: „Alle Webreisen auf dieses Gerät übernehmen“. Eigene Änderungen und bewusst lokal gelöschte Webreisen bleiben geschützt.

Beide Vorgänge können erneut gestartet werden. Reise-IDs, Quittungen und Ausgangsrevisionen verhindern doppelte Veröffentlichungen. Der Sammelauftrag zum Server markiert alle ausgewählten Reisen vorab dauerhaft als ausstehend; Wiederholungsversuche bleiben nach einem App-Neustart möglich. Ein bestätigter manueller Vergleich hat Vorrang.

## Laufender Betrieb

Bearbeitungen werden zuerst lokal gespeichert und anschließend automatisch übertragen. Bei fehlendem Netz bleiben sie ausstehend. Während aktiver Nutzung prüft die App im Minutentakt und bei Aktivierung auf offene Übertragungen und neue Revisionen bereits verknüpfter Reisen. Unveränderte Reisen werden übernommen. Bei Änderungen auf beiden Seiten wird ein manueller Vergleich erforderlich. Eine Bearbeitung während eines Downloads wird erneut unter der Katalogsperre geprüft und nicht überschrieben.

Neue, bislang nicht übernommene Serverreisen werden über die Sammelübernahme hinzugefügt. Eine Aktualisierung der sichtbaren Ansicht wird angeboten, damit noch nicht gespeicherte Formulareingaben erhalten bleiben.

iOS kann zusätzlich Hintergrundabrufe ausführen. Deren Zeitpunkt bestimmt das Betriebssystem; es besteht keine Garantie für sofortigen Empfang oder einen bestimmten Zeitpunkt. Es ist kein APNs-Pushdienst erforderlich.

## Teilfunktion bei fehlenden Voraussetzungen

| Situation | Verhalten |
|---|---|
| Kein Server verbunden | Lokaler Import, Bearbeitung, Karten und Diashow bleiben verfügbar; Serververwaltung und Synchronisation werden ausgeblendet. |
| Eingerichteter Server vorübergehend offline | Lokale Arbeit bleibt möglich; Verwaltung bleibt sichtbar, Serveraktionen melden die fehlende Verbindung; Änderungen bleiben ausstehend. |
| Anmeldung abgelaufen | Erneut verbinden; lokale Reisen bleiben erhalten. |
| OneDrive-Zugriff fehlt oder falsches Konto | Texte können lokal bearbeitet werden; Quellenprüfung/Übertragung scheitert mit Hinweis. |
| Servermigration fehlt | Server aktualisieren und Installationsprüfung durchführen; lokale Daten bleiben erhalten. |
| Lokale Ordner oder iPhone-Fotoalben | In der App nutzbar; keine Übertragung dieser Reisen auf den Server. |
| Windows | Keine HealthKit-Einrichtung; vom Server übernommene Tages-Schrittwerte werden angezeigt. |

Ein Geräte-Löschen ist keine Serverlöschung. Ein Serverwechsel bewahrt Katalog und je Server getrennte Ausgangsstände; ein offener bestätigter Vergleich muss vorher abgeschlossen werden. Gemeinsame lokale Reiseinhalte bleiben beim Wechsel erhalten und werden am Ziel nicht ungefragt überschrieben.

## Schritte, Datenschutz und Sicherung

Auf iOS legt der Besitzer seinen Teilnehmernamen fest und aktiviert freiwillig „Meine Schritte“. Teilnehmer als eigene Einträge mit Komma, Semikolon, Zeilenumbruch oder „und“ trennen; bloße Teilzeichenfolgen reichen nicht. „Schritte freigeben und aktualisieren“ berücksichtigt alle passenden lokalen Reisen. Windows liest keine HealthKit-Daten.

Tagesgrenzen richten sich derzeit nach der aktuellen iPhone-Zeitzone. Historische Ortszeitzonen werden nicht automatisch rekonstruiert. Schrittwerte können für alle Personen mit Reisezugriff sichtbar werden. Abschalten stoppt neue Übernahmen; bereits übernommene Werte müssen gesondert entfernt werden. Synchronisierte Löschungen ändern den aktuellen Reisestand, nicht alte Sicherungen. Betreiber müssen Aufbewahrung und vollständige Löschung separat regeln.

Der iOS-Katalog wird wegen möglicherweise enthaltener HealthKit-Daten von der iCloud-Gerätesicherung ausgeschlossen. Server- und App-Abgleichsicherungen enthalten Metadaten und Originalreferenzen, keine Sicherung der Originalfotos. Serverbackups und OneDrive-Originale müssen unabhängig gesichert werden.

## Empfohlene OneDrive-Struktur

Ein Ordner pro Reise, zum Beispiel `Reisen/2026-09-03 Südtirol/`. Unterordner für Tage, Fotos oder Videos sind optional und werden mitgelesen. Die Medienzuordnung verwendet Aufnahmedaten; die Identität verwendet OneDrive-IDs. Vorhandene anders benannte Ordner sind zulässig.

Das Downloadpaket installiert keine persönliche Reiseautomatik und aktiviert keine Cronjobs oder Quellenbereinigung. Die separat einrichtbare allgemeine Fotoautomatik ist in FOTOAUTOMATIK.md beschrieben. Es verschiebt oder löscht keine OneDrive-Originale beim normalen Geräteabgleich.

## Zentraler Verwaltungsmodus

Unter „Alle Reisen“ schaltet „Reisen verwalten“ die Bearbeitung für alle Reisen ein. Der Modus bleibt beim Wechsel zwischen Reisen und Reisetagen aktiv und wird mit „Verwaltungsmodus“ angezeigt. „Verwaltung schließen“ unter „Alle Reisen“ beendet ihn. Nach einem App-Neustart beziehungsweise Neuladen der Website startet die Ansicht ohne Bearbeitung. Ein eigener Bearbeiten-Knopf im Reisemenü entfällt; bestehende Administratorrechte bleiben erforderlich.

## Weltkarte und Verbindungen

Einfachklick auf eine Station in der Weltkarte öffnet die Reiseübersicht mit den Reisetagen. Doppelklick startet die Diashow ab dieser Station. Auf Touchgeräten gilt entsprechend einmal beziehungsweise zweimal tippen. Ein Hauptort ohne Aufnahmen öffnet die Reiseübersicht.

„Anmeldung & Verbindungen“ zeigt in der Website das angemeldete Konto und die Adresse der aktuellen Website. In den Apps werden das verbundene OneDrive-Konto, die konfigurierte Website-Adresse und der Verbindungsstatus angezeigt. Eine gespeicherte Website-Adresse allein bedeutet noch keine aktive Verbindung.
