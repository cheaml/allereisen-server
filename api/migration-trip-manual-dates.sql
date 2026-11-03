-- Prefer tools/migrate-trip-manual-dates.php: backs up trips and checks existing columns.
ALTER TABLE trips ADD COLUMN manual_starts_on DATE NULL, ADD COLUMN manual_ends_on DATE NULL;
