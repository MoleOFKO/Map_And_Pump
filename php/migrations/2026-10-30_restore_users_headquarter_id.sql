ALTER TABLE `users`
    ADD COLUMN `headquarter_id` int DEFAULT NULL AFTER `license_id`,
    ADD KEY `fk_users_headquarter_id_idx` (`headquarter_id`),
    ADD CONSTRAINT `fk_users_headquarter_id`
        FOREIGN KEY (`headquarter_id`) REFERENCES `headquarter` (`headquarter_id`)
        ON DELETE CASCADE ON UPDATE CASCADE;

UPDATE `users` u
INNER JOIN `business_account_scope` bas ON bas.`user_id` = u.`user_id`
SET u.`headquarter_id` = bas.`headquarter_id`
WHERE bas.`headquarter_id` IS NOT NULL;

UPDATE `users` u
INNER JOIN `headquarter` h ON h.`headquarters_owner_id` = u.`user_id`
SET u.`headquarter_id` = h.`headquarter_id`
WHERE u.`headquarter_id` IS NULL;