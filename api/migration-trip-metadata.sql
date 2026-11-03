-- Einmalig für Installationen aus der Zeit vor Teilnehmern und Hauptort ausführen.
ALTER TABLE trips ADD COLUMN participants TEXT NULL AFTER intro;
ALTER TABLE trips ADD COLUMN main_location VARCHAR(255) NULL AFTER participants;
ALTER TABLE trips ADD COLUMN main_latitude DECIMAL(10,7) NULL AFTER main_location;
ALTER TABLE trips ADD COLUMN main_longitude DECIMAL(10,7) NULL AFTER main_latitude;
