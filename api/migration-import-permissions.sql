ALTER TABLE users ADD COLUMN can_import TINYINT(1) NOT NULL DEFAULT 0;
-- Grant only accounts already linked to Microsoft when this migration runs.
UPDATE users SET can_import=1 WHERE microsoft_id IS NOT NULL AND microsoft_id<>'';
