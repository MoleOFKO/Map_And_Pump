ALTER TABLE `station_business_scope`
    MODIFY COLUMN `branch_manager_user_id` int DEFAULT NULL;

ALTER TABLE `invitations`
    MODIFY COLUMN `station_id` int DEFAULT NULL;