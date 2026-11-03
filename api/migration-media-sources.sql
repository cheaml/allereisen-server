-- Apply once before deploying the media-source release. Existing OneDrive IDs remain valid.
ALTER TABLE trips ADD COLUMN source_provider VARCHAR(16) NOT NULL DEFAULT 'onedrive';
ALTER TABLE media_items
  ADD COLUMN source_provider VARCHAR(16) NOT NULL DEFAULT 'onedrive',
  ADD COLUMN source_item_id VARCHAR(512) NULL,
  ADD COLUMN storage_path VARCHAR(255) NULL,
  ADD COLUMN local_original_path TEXT NULL,
  ADD COLUMN local_original_root TEXT NULL,
  ADD COLUMN mime_type VARCHAR(100) NULL;
UPDATE media_items SET source_item_id=onedrive_item_id;
ALTER TABLE media_items ADD UNIQUE KEY media_source_identity (trip_id, source_provider, source_item_id);
CREATE TABLE media_source_connections (
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(16) NOT NULL,
  account_id VARCHAR(128) NULL,
  refresh_token_enc TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id,provider),
  CONSTRAINT fk_source_connection_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
