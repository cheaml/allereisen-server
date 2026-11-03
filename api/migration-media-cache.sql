-- Einmalig auf bestehenden Datenbanken ausführen.
-- thumbnail_url existiert bereits in älteren Schemata; dieser Index beschleunigt
-- Station-, Tages- und Vorschaubild-Abfragen.
ALTER TABLE media_items ADD INDEX media_by_stop_time (trip_id, stop_id, taken_at);
