-- Additive: legacy copied media mappings and existing media IDs stay intact.
CREATE TABLE IF NOT EXISTS original_media_memberships (
  source_key CHAR(64) PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  account_id VARCHAR(128) NOT NULL,
  drive_id VARCHAR(128) NOT NULL,
  source_item_id VARCHAR(255) NOT NULL,
  source_version VARCHAR(512) NOT NULL,
  target_item_id VARCHAR(255) NOT NULL,
  target_version VARCHAR(512) NOT NULL,
  source_sha256 CHAR(64) NOT NULL,
  target_sha256 CHAR(64) NOT NULL,
  byte_count BIGINT UNSIGNED NOT NULL,
  trip_id CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY original_trip_membership (user_id,trip_id,drive_id,source_item_id),
  CONSTRAINT fk_original_membership_owner FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
