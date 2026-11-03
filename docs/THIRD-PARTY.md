# Fremdkomponenten, Daten und Dienste

Der eigene Programmcode steht unter der GNU Affero General Public License v3.0 (siehe `LICENSE`). Diese Lizenz gilt nicht für die nachfolgend genannten Daten, Bibliotheken und Dienste Dritter; für sie gelten ausschließlich deren eigene Bedingungen. Das Projekt erteilt keine zusätzlichen Rechte an Drittanbieterdiensten, Bildern oder Nutzerdaten. Die Tabelle ist ein technisches Inventar; Betreiber prüfen die Bedingungen für ihren eigenen Einsatz.

| Bestandteil | Quelle / Bedingungen | Stand |
|---|---|---|
| Leaflet 1.9.4, zur Laufzeit vom CDN | https://github.com/Leaflet/Leaflet/blob/v1.9.4/LICENSE (BSD-2-Clause) | Kein gebündelter Bibliothekscode; CDN-Abhängigkeit dokumentiert |
| GeoNames-Ortsdaten | https://www.geonames.org/export/ (CC BY 4.0) | Lokale Kartenlabels, Attribution in der Oberfläche |
| Natural Earth-Geometrien | https://www.naturalearthdata.com/about/terms-of-use/ (Public Domain) | Lokale Routingdaten |
| OpenStreetMap-Daten / Ausschnitte | https://www.openstreetmap.org/copyright (ODbL) | Attribution und Pflichten für verteilte abgeleitete Daten prüfen |
| Öffentliche OSM-Dienste | https://operations.osmfoundation.org/policies/tiles/ und https://operations.osmfoundation.org/policies/nominatim/ | Nutzungsgrenzen einhalten; bei kommerziellem Maßstab eigenen/geeigneten Anbieter vorsehen |
| Esri World Imagery | https://www.esri.com/en-us/legal/terms/full-master-agreement | Externe Satellitenkarten; Nutzungsberechtigung und vollständige Attribution vor Verkauf klären |
| Google Fonts | https://fonts.google.com/ | Extern geladene Schriften; konkrete Fontlizenzen und Datenschutzeinbindung prüfen |
| Unsplash | https://unsplash.com/license | Externe Bilder/Fallbacks und vorhandene Produktassets vor Vertrieb prüfen |
| Microsoft Graph/OneDrive | https://learn.microsoft.com/graph/overview | Eigene OAuth-App und Berechtigungen pro Installation |
| Dropbox | https://www.dropbox.com/developers/reference/developer-guide | Optional, eigene OAuth-App |
| Open-Meteo | https://open-meteo.com/en/terms | Kostenlose API nur für nichtkommerzielle Nutzung. Für kommerzielle Installationen geeigneten Tarif/Endpunkte/API-Key konfigurieren oder Wetter deaktivieren |
| Gemini | https://ai.google.dev/gemini-api/docs | Optional; eigenes Konto, API-Key, Kosten und Übermittlung von Bildern/Texten beachten |

## Mitgelieferte Kartendaten

Die Dateien `coastal-labels.json`, `map-labels/*.json` und die Wasserrouting-Daten (`water-routing-*.json`) sind aus den oben genannten Quellen abgeleitet. Für Bestandteile aus GeoNames gilt CC BY 4.0 (Namensnennung „GeoNames, geonames.org“). Bestandteile aus OpenStreetMap stehen unter der Open Database License (ODbL 1.0): © OpenStreetMap-Mitwirkende, https://www.openstreetmap.org/copyright. Wer diese Daten weiterverteilt oder verändert, muss die Namensnennung beibehalten und abgeleitete Datenbanken ebenfalls unter der ODbL bereitstellen. Die AGPL des Programmcodes gilt für diese Daten nicht. Die Weboberfläche zeigt die Quellen in der Kartenattribution an.

Für den Betrieb muss insbesondere die Herkunft und Lizenzierung der gebündelten OSM-Ausschnitte und Produktbilder/Icons sowie die Nutzung von Esri geklärt werden. Benötigte Lizenztexte/Attributionen müssen mit der freigegebenen Version mitgeliefert werden. Der Betreiber erhält keine privaten Konten, Tokens oder API-Keys des Entwicklers.
