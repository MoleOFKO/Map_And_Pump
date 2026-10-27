CREATE TABLE IF NOT EXISTS `account_password_setup_tokens` (
  `token_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_bin NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uq_account_password_setup_token_hash` (`token_hash`),
  KEY `idx_account_password_setup_user` (`user_id`,`expires_at`),
  CONSTRAINT `fk_account_password_setup_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `hq_password_setup_tokens` (
  `token_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_bin NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uq_hq_password_setup_token_hash` (`token_hash`),
  KEY `idx_hq_password_setup_user` (`user_id`,`expires_at`),
  CONSTRAINT `fk_hq_password_setup_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT IGNORE INTO `account_password_setup_tokens`
    (`user_id`, `token_hash`, `expires_at`, `used_at`, `created_at`)
SELECT `user_id`, `token_hash`, `expires_at`, `used_at`, `created_at`
FROM `hq_password_setup_tokens`;

DROP TABLE `hq_password_setup_tokens`;