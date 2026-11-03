-- Additive only. Existing trips/media/station identities are retained.
CREATE TABLE IF NOT EXISTS automation_connections (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  account_id VARCHAR(128) NOT NULL,
  drive_id VARCHAR(128) NOT NULL,
  photos_item_id VARCHAR(255) NOT NULL,
  refresh_token_enc TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_automation_connection_owner FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS automation_media_map (
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
  UNIQUE KEY automation_target (user_id,drive_id,target_item_id),
  CONSTRAINT fk_automation_owner FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
