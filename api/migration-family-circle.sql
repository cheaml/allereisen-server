-- Run once with the installation account, not during ordinary HTTP requests.
CREATE TABLE IF NOT EXISTS family_circle_contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id BIGINT UNSIGNED NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  email VARCHAR(320) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_family_owner_email (owner_id,email),
  CONSTRAINT fk_family_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
