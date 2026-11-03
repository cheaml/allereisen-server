# Versionsstand

Server zu den Apps **0.2.68** (November 2026). Enthält Geräteabgleich, revisionsgesicherte Sammelübernahme, Serverdiagnose, Rechte- und Gästeverwaltung, Kontoangaben für die App-Anzeige, Kontolöschung durch die Nutzer selbst, Kontowahl beim Verbinden einer App sowie die Trennung von App-Verwaltung (Administratoren) und Reiseverwaltung (Besitzer). Installation und Grenzen: INSTALLATION.md und APPS.md.

Lizenz: AGPL-3.0 für den eigenen Code; Fremdkomponenten siehe THIRD-PARTY.md.

Das Repository enthält keine produktive Konfiguration, keine privaten Gästevorgaben und keine aktivierten Automatikjobs. Automatisierte Tests und der Paketbau ersetzen nicht die Abnahme einer neuen Hostinginstallation mit echtem Microsoft-Konto.

Eine vorhandene Installation nicht durch bloßes Überschreiben aktualisieren: zuerst sichern und die erforderlichen Datenbankmigrationen gezielt ausführen (OPERATIONS.md).

Die allgemeine Fotoautomatik ist optional und standardmäßig deaktiviert. Sie verwendet konfigurierbare Quell-/Zielordner, 30 km als vorgeschlagenen Heimatradius, beibehaltene Mindestauflösung und Heimatfotos als Reisegrenze. Die Abnahme mit eigener Microsoft-Verbindung und begrenzten Originalen bleibt beim Betreiber. Anleitung: FOTOAUTOMATIK.md.
