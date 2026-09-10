-- Make branch ownership explicit while preserving the existing station/customer tables.
ALTER TABLE stations ADD COLUMN IF NOT EXISTS branch_id INT NULL;
ALTER TABLE stations ADD KEY IF NOT EXISTS idx_stations_branch (branch_id);
ALTER TABLE staff ADD COLUMN IF NOT EXISTS user_id INT NULL;
ALTER TABLE staff ADD UNIQUE KEY IF NOT EXISTS uq_staff_user (user_id);
ALTER TABLE staff ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL AFTER contact;

-- Existing fixtures historically used the same numeric ID for station and branch.
UPDATE stations s
INNER JOIN branches b ON b.branch_id = s.station_id
SET s.branch_id = b.branch_id
WHERE s.branch_id IS NULL;

-- A branch manager owns one branch; remove stale legacy assignments from the
-- old multi-station scope model while retaining the user's current branch.
DELETE sbs
FROM station_business_scope sbs
INNER JOIN users u ON u.user_id = sbs.branch_manager_user_id
INNER JOIN stations s ON s.station_id = sbs.station_id
WHERE u.branch_id IS NOT NULL
    AND COALESCE(s.branch_id, s.station_id) <> u.branch_id;

-- Add the foreign keys only when the deployment has not already added them.
SET @fk_station_branch = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'stations' AND CONSTRAINT_NAME = 'fk_stations_branch_id'
);
SET @sql_station_branch = IF(@fk_station_branch = 0,
    'ALTER TABLE stations ADD CONSTRAINT fk_stations_branch_id FOREIGN KEY (branch_id) REFERENCES branches(branch_id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE stmt_station_branch FROM @sql_station_branch;
EXECUTE stmt_station_branch;
DEALLOCATE PREPARE stmt_station_branch;

SET @fk_staff_user = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND CONSTRAINT_NAME = 'fk_staff_user_id'
);
SET @sql_staff_user = IF(@fk_staff_user = 0,
    'ALTER TABLE staff ADD CONSTRAINT fk_staff_user_id FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE stmt_staff_user FROM @sql_staff_user;
EXECUTE stmt_staff_user;
DEALLOCATE PREPARE stmt_staff_user;
