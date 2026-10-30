ALTER TABLE headquarter
    ADD COLUMN account_deletion_requested_at datetime DEFAULT NULL,
    ADD COLUMN account_deletion_scheduled_for datetime DEFAULT NULL;

UPDATE headquarter h
INNER JOIN users u
    ON u.headquarter_id = h.headquarter_id
   AND u.user_id = h.headquarters_owner_id
SET h.account_deletion_requested_at = u.account_deletion_requested_at,
    h.account_deletion_scheduled_for = u.account_deletion_scheduled_for
WHERE u.account_deletion_requested_at IS NOT NULL
   OR u.account_deletion_scheduled_for IS NOT NULL;

UPDATE staff st
INNER JOIN users u ON u.user_id = st.user_id
SET st.branch_id = u.branch_id
WHERE u.branch_id IS NOT NULL
  AND (u.role_id = 5 OR u.business_role_id = 5);

INSERT INTO business_account_scope
    (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
SELECT u.user_id,
       CASE WHEN u.business_role_id = 4 OR u.role_id = 4 THEN 4 ELSE 5 END,
       u.headquarter_id,
  NULL,
       u.branch_id,
       'active'
FROM users u
WHERE u.branch_id IS NOT NULL
  AND (u.role_id IN (4, 5) OR u.business_role_id IN (4, 5))
ON DUPLICATE KEY UPDATE
    headquarter_id = COALESCE(VALUES(headquarter_id), headquarter_id),
    station_id = COALESCE(VALUES(station_id), station_id);

UPDATE branches b
INNER JOIN users u ON u.branch_id = b.branch_id
SET b.manager_user_id = u.user_id
WHERE b.manager_user_id IS NULL
  AND (u.role_id = 4 OR u.business_role_id = 4);

UPDATE station_business_scope sbs
INNER JOIN branches b ON b.branch_id = sbs.station_id
SET sbs.branch_manager_user_id = b.manager_user_id
WHERE sbs.branch_manager_user_id IS NULL
  AND b.manager_user_id IS NOT NULL;

INSERT INTO station_business_scope (station_id, headquarter_id, branch_manager_user_id)
SELECT b.branch_id, bas.headquarter_id, b.manager_user_id
FROM branches b
INNER JOIN users u ON u.user_id = b.manager_user_id
INNER JOIN business_account_scope bas ON bas.user_id = u.user_id
WHERE b.manager_user_id IS NOT NULL
  AND bas.headquarter_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    branch_manager_user_id = COALESCE(branch_manager_user_id, VALUES(branch_manager_user_id));

ALTER TABLE users
    DROP FOREIGN KEY fk_business_branch_id,
    DROP INDEX fk_business_branch_id_idx,
    DROP COLUMN branch_id,
    DROP COLUMN account_deletion_requested_at,
    DROP COLUMN account_deletion_scheduled_for;