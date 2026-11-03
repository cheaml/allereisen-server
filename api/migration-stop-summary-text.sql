-- Existing installations may still limit station texts to a short VARCHAR column.
ALTER TABLE trip_stops MODIFY summary TEXT NULL;
