ALTER TABLE media_items
  ADD COLUMN capture_source VARCHAR(32) NULL,
  ADD COLUMN capture_details TEXT NULL;
