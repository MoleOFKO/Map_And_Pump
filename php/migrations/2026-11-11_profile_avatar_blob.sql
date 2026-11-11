SET @users_profile_avatar_data_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'profile_avatar_data'
);
SET @users_profile_avatar_data_ddl := IF(
    @users_profile_avatar_data_exists = 0,
    'ALTER TABLE users ADD COLUMN profile_avatar_data MEDIUMBLOB NULL',
    'SELECT 1'
);
PREPARE users_profile_avatar_data_stmt FROM @users_profile_avatar_data_ddl;
EXECUTE users_profile_avatar_data_stmt;
DEALLOCATE PREPARE users_profile_avatar_data_stmt;

SET @users_profile_avatar_mime_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'profile_avatar_mime'
);
SET @users_profile_avatar_mime_ddl := IF(
    @users_profile_avatar_mime_exists = 0,
    'ALTER TABLE users ADD COLUMN profile_avatar_mime VARCHAR(50) NULL',
    'SELECT 1'
);
PREPARE users_profile_avatar_mime_stmt FROM @users_profile_avatar_mime_ddl;
EXECUTE users_profile_avatar_mime_stmt;
DEALLOCATE PREPARE users_profile_avatar_mime_stmt;

SET @hq_profile_avatar_data_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'headquarter'
      AND COLUMN_NAME = 'profile_avatar_data'
);
SET @hq_profile_avatar_data_ddl := IF(
    @hq_profile_avatar_data_exists = 0,
    'ALTER TABLE headquarter ADD COLUMN profile_avatar_data MEDIUMBLOB NULL',
    'SELECT 1'
);
PREPARE hq_profile_avatar_data_stmt FROM @hq_profile_avatar_data_ddl;
EXECUTE hq_profile_avatar_data_stmt;
DEALLOCATE PREPARE hq_profile_avatar_data_stmt;

SET @hq_profile_avatar_mime_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'headquarter'
      AND COLUMN_NAME = 'profile_avatar_mime'
);
SET @hq_profile_avatar_mime_ddl := IF(
    @hq_profile_avatar_mime_exists = 0,
    'ALTER TABLE headquarter ADD COLUMN profile_avatar_mime VARCHAR(50) NULL',
    'SELECT 1'
);
PREPARE hq_profile_avatar_mime_stmt FROM @hq_profile_avatar_mime_ddl;
EXECUTE hq_profile_avatar_mime_stmt;
DEALLOCATE PREPARE hq_profile_avatar_mime_stmt;
