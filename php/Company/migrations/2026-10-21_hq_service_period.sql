ALTER TABLE `headquarter`
    ADD COLUMN `service_expires_at` DATETIME DEFAULT NULL AFTER `expire_at`,
    ADD COLUMN `included_stations_free_until` DATETIME DEFAULT NULL AFTER `service_expires_at`;