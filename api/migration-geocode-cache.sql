CREATE TABLE IF NOT EXISTS geocode_cache (
  latitude_key DECIMAL(6,2) NOT NULL,
  longitude_key DECIMAL(6,2) NOT NULL,
  place_name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (latitude_key, longitude_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
