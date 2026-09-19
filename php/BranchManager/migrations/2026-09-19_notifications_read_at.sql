-- ============================================================
-- MAP & PUMP  —  add read-state to notifications
-- Adds a nullable read_at column so the bell / alerts UI can persist
-- which notifications a manager has cleared. Non-destructive.
--
-- NOTE: The Branch Manager pages also add this column automatically on
-- first load (see notif_has_read_column() in BranchManager/_helpers.php).
-- Run this only if you prefer to apply schema changes manually / the DB
-- user has no ALTER privilege at runtime.
-- ============================================================

ALTER TABLE `notifications`
    ADD COLUMN `read_at` TIMESTAMP NULL DEFAULT NULL AFTER `delivery_note`;

-- Optional: speed up "unread for this manager" lookups
CREATE INDEX `idx_notifications_recipient_read`
    ON `notifications` (`recipient_user_id`, `read_at`);
