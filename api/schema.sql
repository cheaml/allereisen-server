CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  microsoft_id VARCHAR(128) NULL UNIQUE,
  email VARCHAR(320) NULL UNIQUE,
  display_name VARCHAR(255) NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  can_import TINYINT(1) NOT NULL DEFAULT 0,
  password_hash VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE family_circle_contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id BIGINT UNSIGNED NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  email VARCHAR(320) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_family_owner_email (owner_id,email),
  CONSTRAINT fk_family_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE oauth_tokens (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  refresh_token_enc TEXT NOT NULL,
  expires_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trips (
  id CHAR(36) PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  publication_status VARCHAR(16) NOT NULL DEFAULT 'approved',
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  intro TEXT NULL,
  participants TEXT NULL,
  main_location VARCHAR(255) NULL,
  main_latitude DECIMAL(10,7) NULL,
  main_longitude DECIMAL(10,7) NULL,
  source_folder VARCHAR(1024) NULL,
  source_provider VARCHAR(16) NOT NULL DEFAULT 'onedrive',
  starts_on DATE NULL,
  ends_on DATE NULL,
  manual_starts_on DATE NULL,
  manual_ends_on DATE NULL,
  media_count INT UNSIGNED NOT NULL DEFAULT 0,
  cover_url TEXT NULL,
  media_synced_at DATETIME NULL,
  capture_dates_updated_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_trips_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX trips_owner (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Freigaben gelten für eine konkrete E-Mail-Adresse, auch vor der Kontoanlage.
CREATE TABLE trip_guest_access (
  trip_id CHAR(36) NOT NULL,
  email VARCHAR(320) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (trip_id, email),
  CONSTRAINT fk_trip_guest_access_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  INDEX trip_guest_access_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dauerhafte Archivfreigabe, unabhaengig von einzelnen Reisen.
CREATE TABLE all_trips_guest_access (
  email VARCHAR(320) PRIMARY KEY,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trip_invitations (
  id CHAR(36) PRIMARY KEY,
  trip_id CHAR(36) NOT NULL,
  email VARCHAR(320) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_trip_invitations_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  INDEX trip_invitations_lookup (email, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trip_stops (
  id CHAR(36) PRIMARY KEY,
  trip_id CHAR(36) NOT NULL,
  position INT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  transport_to_next VARCHAR(32) NULL,
  summary TEXT NULL,
  CONSTRAINT fk_stops_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  UNIQUE KEY stop_position (trip_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trip_day_summaries (
  trip_id CHAR(36) NOT NULL,
  day_date DATE NOT NULL,
  summary TEXT NOT NULL,
  photo_media_id CHAR(36) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (trip_id, day_date),
  CONSTRAINT fk_day_summaries_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE media_items (
  id CHAR(36) PRIMARY KEY,
  trip_id CHAR(36) NOT NULL,
  stop_id CHAR(36) NULL,
  onedrive_item_id VARCHAR(255) NOT NULL,
  source_provider VARCHAR(16) NOT NULL DEFAULT 'onedrive',
  source_item_id VARCHAR(512) NULL,
  storage_path VARCHAR(255) NULL,
  local_original_path TEXT NULL,
  local_original_root TEXT NULL,
  mime_type VARCHAR(100) NULL,
  name VARCHAR(1024) NOT NULL,
  media_type ENUM('photo','video') NOT NULL,
  taken_at DATETIME NULL,
  capture_source VARCHAR(32) NULL,
  capture_details TEXT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  web_url TEXT NULL,
  thumbnail_url TEXT NULL,
  CONSTRAINT fk_media_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_media_stop FOREIGN KEY (stop_id) REFERENCES trip_stops(id) ON DELETE SET NULL,
  UNIQUE KEY media_source_identity (trip_id, source_provider, source_item_id),
  UNIQUE KEY onedrive_per_trip (trip_id, onedrive_item_id),
  INDEX media_by_stop_time (trip_id, stop_id, taken_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE geocode_cache (
  latitude_key DECIMAL(6,2) NOT NULL,
  longitude_key DECIMAL(6,2) NOT NULL,
  place_name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (latitude_key, longitude_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE media_source_connections (
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(16) NOT NULL,
  account_id VARCHAR(128) NULL,
  refresh_token_enc TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id,provider),
  CONSTRAINT fk_source_connection_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trip_manual_routes (
 trip_id CHAR(36) NOT NULL, route_key CHAR(64) NOT NULL, payload MEDIUMTEXT NOT NULL,
 PRIMARY KEY(trip_id,route_key),
 CONSTRAINT fk_manual_routes_trip FOREIGN KEY(trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
