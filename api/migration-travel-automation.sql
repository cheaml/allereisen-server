-- Separate general-automation connection. Does not replace the personal worker.
CREATE TABLE IF NOT EXISTS travel_automation_connections (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  account_id VARCHAR(128) NOT NULL,
  drive_id VARCHAR(128) NOT NULL,
  root_item_id VARCHAR(255) NOT NULL,
  refresh_token_enc TEXT NOT NULL,
  worker_state_id CHAR(64) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_travel_automation_connection_owner FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
