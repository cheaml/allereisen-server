-- Existing installations created with an older short text column need this once.
ALTER TABLE trip_day_summaries MODIFY summary TEXT NOT NULL;
