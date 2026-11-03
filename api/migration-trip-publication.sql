-- Existing trips retain their current visibility.
ALTER TABLE trips
 ADD COLUMN publication_status VARCHAR(16) NOT NULL DEFAULT 'approved',
 ADD COLUMN approved_by BIGINT UNSIGNED NULL,
 ADD COLUMN approved_at DATETIME NULL;
