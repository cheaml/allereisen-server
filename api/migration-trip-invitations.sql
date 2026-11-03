-- Einmalig auf einer bestehenden Datenbank ausführen.
CREATE TABLE trip_guest_access (
  trip_id CHAR(36) NOT NULL,
  email VARCHAR(320) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (trip_id, email),
  CONSTRAINT fk_trip_guest_access_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  INDEX trip_guest_access_email (email)
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
