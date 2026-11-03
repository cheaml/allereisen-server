-- Einmalig auf einer bestehenden Datenbank ausführen.
ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER display_name;
ALTER TABLE trips ADD COLUMN intro TEXT NULL AFTER title;

-- Die gewünschte Person zur Administration berechtigen (E-Mail anpassen).
-- UPDATE users SET is_admin=1 WHERE email='admin@example.com';
