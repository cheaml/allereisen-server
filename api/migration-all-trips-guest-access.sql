-- Generic migration: does not grant access to any existing or new person.
CREATE TABLE IF NOT EXISTS all_trips_guest_access (
 email VARCHAR(320) PRIMARY KEY,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
