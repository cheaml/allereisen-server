-- Einmalig auf einer bestehenden Datenbank ausführen.
ALTER TABLE users MODIFY microsoft_id VARCHAR(128) NULL;
ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL AFTER is_admin;
ALTER TABLE users ADD UNIQUE KEY users_email (email);
