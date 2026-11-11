SET @hq_profile_avatar_column_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'headquarter'
      AND COLUMN_NAME = 'profile_avatar_url'
);

SET @hq_profile_avatar_ddl := IF(
    @hq_profile_avatar_column_exists = 0,
    'ALTER TABLE headquarter ADD COLUMN profile_avatar_url VARCHAR(500) NULL',
    'SELECT 1'
);

PREPARE hq_profile_avatar_stmt FROM @hq_profile_avatar_ddl;
EXECUTE hq_profile_avatar_stmt;
DEALLOCATE PREPARE hq_profile_avatar_stmt;