-- Install before deploying the device API. Existing travel/media rows are unchanged.
CREATE TABLE IF NOT EXISTS trip_day_steps (
  trip_id CHAR(36) NOT NULL,
  day_date DATE NOT NULL,
  payload TEXT NOT NULL,
  PRIMARY KEY(trip_id,day_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS device_sync_codes (
  code_hash CHAR(64) PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  challenge CHAR(43) NOT NULL,
  expires_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS device_sync_devices (
  token_hash CHAR(64) PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS device_sync_backups (
  id CHAR(36) PRIMARY KEY,
  trip_id CHAR(36) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  revision CHAR(64) NOT NULL,
  device_label VARCHAR(100) NOT NULL,
  payload LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(trip_id,user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS device_sync_receipts (
  request_hash CHAR(64) PRIMARY KEY,
  trip_id CHAR(36) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  payload LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
