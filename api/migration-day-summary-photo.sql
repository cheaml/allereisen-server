-- Einmalig auf älteren Installationen ohne photo_media_id ausführen.
-- Vorher mit production-schema-check.sql prüfen, ob die Spalte fehlt.
ALTER TABLE trip_day_summaries
  ADD COLUMN photo_media_id CHAR(36) NULL AFTER summary;
