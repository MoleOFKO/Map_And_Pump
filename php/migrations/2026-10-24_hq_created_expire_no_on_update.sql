-- Part 5: HQ Registered vs License expiry
-- Problem:
--   headquarter.created_at and headquarter.expire_at both used
--   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
--   HQ registration INSERT did not set expire_at, so both columns
--   became "now". Later UPDATEs could also rewrite created_at / expire_at.
--
-- Fix:
--   1) Remove ON UPDATE from created_at and expire_at.
--   2) Backfill expire_at from business_license_expire_date when it was
--      incorrectly equal to created_at but a future license date exists.
--
-- Run against the live map_and_pump database (TiDB / MySQL compatible).
-- Back up first.

-- 1) Schema: created_at stays set on insert only; expire_at has no auto-update.
ALTER TABLE headquarter
  MODIFY COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MODIFY COLUMN `expire_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- 2) Backfill rows where license expiry was lost (expire_at ≈ created_at)
--    but business_license_expire_date holds a later date.
UPDATE headquarter
SET expire_at = CONCAT(LEFT(TRIM(business_license_expire_date), 10), ' 23:59:59')
WHERE business_license_expire_date IS NOT NULL
  AND TRIM(business_license_expire_date) <> ''
  AND LEFT(TRIM(business_license_expire_date), 10) REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
  AND DATE(expire_at) = DATE(created_at)
  AND LEFT(TRIM(business_license_expire_date), 10) > DATE(created_at);
