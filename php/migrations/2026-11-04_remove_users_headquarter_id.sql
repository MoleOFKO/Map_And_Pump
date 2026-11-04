-- Move legacy users.headquarter_id assignments into business_account_scope, then remove the redundant column.

SET @users_has_hq_id = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'headquarter_id'
);

SET @sql = IF(
    @users_has_hq_id > 0,
    'INSERT INTO business_account_scope
        (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
     SELECT u.user_id,
            COALESCE(NULLIF(u.business_role_id, 0), NULLIF(u.role_id, 0), 3),
            u.headquarter_id, NULL, NULL, COALESCE(NULLIF(u.status, ''''), ''active'')
     FROM users u
     WHERE u.headquarter_id IS NOT NULL AND u.headquarter_id > 0
     ON DUPLICATE KEY UPDATE
          business_role_id = COALESCE(NULLIF(business_role_id, 0), VALUES(business_role_id)),
          headquarter_id = COALESCE(headquarter_id, VALUES(headquarter_id))',
    'SELECT 1'
);
PREPARE migrate_users_hq_scope FROM @sql;
EXECUTE migrate_users_hq_scope;
DEALLOCATE PREPARE migrate_users_hq_scope;

UPDATE headquarter h
INNER JOIN (
    SELECT bas.headquarter_id, MIN(bas.user_id) AS owner_user_id
    FROM business_account_scope bas
    INNER JOIN users u ON u.user_id = bas.user_id
    WHERE u.role_id = 3 OR u.business_role_id = 3 OR u.current_role_id = 3
    GROUP BY bas.headquarter_id
) owner_scope ON owner_scope.headquarter_id = h.headquarter_id
SET h.headquarters_owner_id = owner_scope.owner_user_id
WHERE h.headquarters_owner_id IS NULL;

INSERT INTO business_account_scope
    (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
SELECT u.user_id,
       3,
       h.headquarter_id,
       NULL,
       NULL,
       COALESCE(NULLIF(u.status, ''), NULLIF(h.status, ''), 'active')
FROM headquarter h
INNER JOIN users u ON u.user_id = h.headquarters_owner_id
ON DUPLICATE KEY UPDATE
    business_role_id = 3,
    headquarter_id = VALUES(headquarter_id);

SET @users_hq_fk = (
    SELECT CONSTRAINT_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'headquarter_id'
      AND REFERENCED_TABLE_NAME = 'headquarter'
    LIMIT 1
);
SET @sql = IF(
    @users_hq_fk IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE `users` DROP FOREIGN KEY `', REPLACE(@users_hq_fk, '`', '``'), '`')
);
PREPARE drop_users_hq_fk FROM @sql;
EXECUTE drop_users_hq_fk;
DEALLOCATE PREPARE drop_users_hq_fk;

SET @users_hq_index = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'fk_users_headquarter_id_idx'
);
SET @sql = IF(@users_hq_index > 0, 'ALTER TABLE `users` DROP INDEX `fk_users_headquarter_id_idx`', 'SELECT 1');
PREPARE drop_users_hq_index FROM @sql;
EXECUTE drop_users_hq_index;
DEALLOCATE PREPARE drop_users_hq_index;

SET @sql = IF(@users_has_hq_id > 0, 'ALTER TABLE `users` DROP COLUMN `headquarter_id`', 'SELECT 1');
PREPARE drop_users_hq_column FROM @sql;
EXECUTE drop_users_hq_column;
DEALLOCATE PREPARE drop_users_hq_column;
