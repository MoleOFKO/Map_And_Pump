INSERT INTO `business_account_scope`
    (`user_id`, `business_role_id`, `headquarter_id`, `branch_manager_user_id`, `station_id`, `status`)
SELECT u.`user_id`, 3, h.`headquarter_id`, NULL, NULL,
       COALESCE(NULLIF(u.`status`, ''), NULLIF(h.`status`, ''), 'active')
FROM `headquarter` h
INNER JOIN `users` u ON u.`user_id` = h.`headquarters_owner_id`
ON DUPLICATE KEY UPDATE
    `business_role_id` = 3,
    `headquarter_id` = VALUES(`headquarter_id`);