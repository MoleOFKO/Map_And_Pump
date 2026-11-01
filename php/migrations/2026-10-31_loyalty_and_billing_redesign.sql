CREATE TABLE IF NOT EXISTS platform_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_tier_rules (
    tier_code VARCHAR(20) NOT NULL,
    points_threshold INT NOT NULL DEFAULT 0,
    exp_threshold INT NOT NULL DEFAULT 0,
    monthly_budget_mmk DECIMAL(14,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (tier_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_redemption_rules (
    reward_id TINYINT UNSIGNED NOT NULL,
    points_required INT NOT NULL,
    discount_mmk DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (reward_id),
    UNIQUE KEY uq_loyalty_redemption_points (points_required)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_user_tier_progress (
    user_id INT NOT NULL,
    exp INT NOT NULL DEFAULT 0,
    tier_code VARCHAR(20) NOT NULL DEFAULT 'bronze',
    last_evaluated_week DATE DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_tier_pool (
    pool_id INT NOT NULL AUTO_INCREMENT,
    tier_code VARCHAR(20) NOT NULL,
    `year_month` CHAR(7) NOT NULL,
    budget_mmk DECIMAL(14,2) NOT NULL DEFAULT 0,
    spent_mmk DECIMAL(14,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (pool_id),
    UNIQUE KEY uq_loyalty_tier_pool (tier_code, `year_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_ledger (
    ledger_id INT NOT NULL AUTO_INCREMENT,
    party_type VARCHAR(16) NOT NULL,
    party_id INT NOT NULL,
    direction VARCHAR(16) NOT NULL,
    points INT NOT NULL DEFAULT 0,
    discount_mmk DECIMAL(12,2) NULL DEFAULT NULL,
    admin_share_mmk DECIMAL(12,2) NULL DEFAULT NULL,
    company_share_mmk DECIMAL(12,2) NULL DEFAULT NULL,
    booking_id INT NULL DEFAULT NULL,
    usage_id INT NULL DEFAULT NULL,
    station_id INT NULL DEFAULT NULL,
    staff_user_id INT NULL DEFAULT NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 0,
    meta JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ledger_id),
    KEY idx_loyalty_ledger_party (party_type, party_id, created_at),
    KEY idx_loyalty_ledger_usage (usage_id),
    KEY idx_loyalty_ledger_station (station_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_discount_account (
    account_id TINYINT UNSIGNED NOT NULL,
    balance_mmk DECIMAL(14,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_discount_payouts (
    payout_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    headquarter_id INT DEFAULT NULL,
    payout_month CHAR(7) NOT NULL,
    amount_mmk DECIMAL(14,2) NOT NULL,
    note VARCHAR(500) DEFAULT NULL,
    processed_by_user_id INT NOT NULL,
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payout_id),
    KEY idx_loyalty_discount_payout_month (payout_month, processed_at),
    KEY idx_loyalty_discount_payout_hq (headquarter_id, payout_month, processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_company_discount_accounts (
    headquarter_id INT NOT NULL,
    balance_mmk DECIMAL(14,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (headquarter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_discount_allocation_migrations (
    migration_key VARCHAR(100) NOT NULL,
    global_balance_before_mmk DECIMAL(14,2) NOT NULL,
    allocated_balance_mmk DECIMAL(14,2) NOT NULL,
    unallocated_balance_mmk DECIMAL(14,2) NOT NULL,
    completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upgrade installations where the earlier version of this migration already
-- created the payout table without company attribution.
SET @loyalty_payout_hq_column_sql = IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'loyalty_discount_payouts'
          AND COLUMN_NAME = 'headquarter_id'
    ),
    'SELECT 1',
    'ALTER TABLE loyalty_discount_payouts ADD COLUMN headquarter_id INT DEFAULT NULL AFTER payout_id'
);
PREPARE loyalty_payout_hq_column_stmt FROM @loyalty_payout_hq_column_sql;
EXECUTE loyalty_payout_hq_column_stmt;
DEALLOCATE PREPARE loyalty_payout_hq_column_stmt;

SET @loyalty_payout_hq_index_sql = IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'loyalty_discount_payouts'
          AND INDEX_NAME = 'idx_loyalty_discount_payout_hq'
    ),
    'SELECT 1',
    'ALTER TABLE loyalty_discount_payouts ADD KEY idx_loyalty_discount_payout_hq (headquarter_id, payout_month, processed_at)'
);
PREPARE loyalty_payout_hq_index_stmt FROM @loyalty_payout_hq_index_sql;
EXECUTE loyalty_payout_hq_index_stmt;
DEALLOCATE PREPARE loyalty_payout_hq_index_stmt;

CREATE TABLE IF NOT EXISTS hq_station_package_coverage (
    payment_transfer_id BIGINT UNSIGNED NOT NULL,
    headquarter_id INT NOT NULL,
    `year_month` CHAR(7) NOT NULL,
    first_station_number INT NOT NULL DEFAULT 1,
    station_count INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_transfer_id, `year_month`),
    KEY idx_hq_package_coverage_month (headquarter_id, `year_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS company_loyalty_station_slots (
    headquarter_id INT NOT NULL,
    `year_month` CHAR(7) NOT NULL,
    slots_purchased INT NOT NULL DEFAULT 0,
    mmk_slots_purchased INT NOT NULL DEFAULT 0,
    points_spent INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (headquarter_id, `year_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS company_loyalty_station_fees (
    headquarter_id INT NOT NULL,
    station_id INT NOT NULL,
    `year_month` CHAR(7) NOT NULL,
    points_paid INT NOT NULL DEFAULT 0,
    payment_method VARCHAR(16) NOT NULL DEFAULT 'points',
    payment_transfer_id BIGINT UNSIGNED DEFAULT NULL,
    paid_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (headquarter_id, station_id, `year_month`),
    KEY idx_company_station_fees_station_month (station_id, `year_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO platform_settings (setting_key, setting_value) VALUES
    ('loyalty_points_per_liter', '1'),
    ('loyalty_pool_enforced', '1'),
    ('hq_registration_fee_mmk', '2999999'),
    ('hq_package_mmk_per_station_month', '37000')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO loyalty_tier_rules (tier_code, points_threshold, exp_threshold, monthly_budget_mmk) VALUES
    ('bronze', 0, 0, 100000),
    ('silver', 500, 2, 250000),
    ('gold', 999, 4, 500000),
    ('platinum', 1499, 6, 1000000),
    ('diamond', 2000, 8, 2000000)
ON DUPLICATE KEY UPDATE tier_code = VALUES(tier_code);

INSERT INTO loyalty_redemption_rules (reward_id, points_required, discount_mmk) VALUES
    (1, 500, 1000),
    (2, 999, 2000),
    (3, 1499, 3000)
ON DUPLICATE KEY UPDATE reward_id = VALUES(reward_id);

INSERT INTO loyalty_discount_account (account_id, balance_mmk) VALUES (1, 0)
ON DUPLICATE KEY UPDATE account_id = VALUES(account_id);

CREATE TEMPORARY TABLE tmp_loyalty_hq_discount_backfill (
    headquarter_id INT NOT NULL,
    balance_mmk DECIMAL(14,2) NOT NULL,
    PRIMARY KEY (headquarter_id)
) ENGINE=InnoDB;

-- Allocate the current net balance pro rata by eligible discounts mapped to an HQ;
-- unmapped historical discounts remain in loyalty_discount_account.
INSERT INTO tmp_loyalty_hq_discount_backfill (headquarter_id, balance_mmk)
SELECT matched.headquarter_id,
       FLOOR(global_account.balance_mmk * matched.historical_discount_mmk / totals.total_discount_mmk * 100) / 100
FROM (
    SELECT sbs.headquarter_id, SUM(uh.discount_mmk) AS historical_discount_mmk
    FROM usage_history uh
    INNER JOIN station_business_scope sbs ON sbs.station_id = uh.station_id
    WHERE LOWER(uh.status) = 'filled'
      AND COALESCE(uh.is_internal, 0) = 0
      AND uh.discount_mmk > 0
    GROUP BY sbs.headquarter_id
) matched
CROSS JOIN (
    SELECT COALESCE(SUM(uh.discount_mmk), 0) AS total_discount_mmk
    FROM usage_history uh
    WHERE LOWER(uh.status) = 'filled'
      AND COALESCE(uh.is_internal, 0) = 0
      AND uh.discount_mmk > 0
) totals
CROSS JOIN (
    SELECT balance_mmk
    FROM loyalty_discount_account
    WHERE account_id = 1
) global_account
WHERE totals.total_discount_mmk > 0
  AND global_account.balance_mmk > 0;

START TRANSACTION;

INSERT INTO loyalty_company_discount_accounts (headquarter_id, balance_mmk)
SELECT backfill.headquarter_id, backfill.balance_mmk
FROM tmp_loyalty_hq_discount_backfill backfill
WHERE NOT EXISTS (
    SELECT 1
    FROM loyalty_discount_allocation_migrations
    WHERE migration_key = '2026-10-31_company_discount_allocation'
)
ON DUPLICATE KEY UPDATE balance_mmk = VALUES(balance_mmk);

UPDATE loyalty_discount_account
SET balance_mmk = GREATEST(
    0,
    balance_mmk - (
        SELECT COALESCE(SUM(balance_mmk), 0)
        FROM tmp_loyalty_hq_discount_backfill
    )
)
WHERE account_id = 1
  AND NOT EXISTS (
      SELECT 1
      FROM loyalty_discount_allocation_migrations
      WHERE migration_key = '2026-10-31_company_discount_allocation'
  );

INSERT INTO loyalty_discount_allocation_migrations (
    migration_key,
    global_balance_before_mmk,
    allocated_balance_mmk,
    unallocated_balance_mmk
)
SELECT '2026-10-31_company_discount_allocation',
       legacy.balance_mmk + allocated.amount_mmk,
       allocated.amount_mmk,
       legacy.balance_mmk
FROM loyalty_discount_account legacy
CROSS JOIN (
    SELECT COALESCE(SUM(balance_mmk), 0) AS amount_mmk
    FROM tmp_loyalty_hq_discount_backfill
) allocated
WHERE legacy.account_id = 1
  AND NOT EXISTS (
      SELECT 1
      FROM loyalty_discount_allocation_migrations
      WHERE migration_key = '2026-10-31_company_discount_allocation'
  );

COMMIT;
DROP TEMPORARY TABLE tmp_loyalty_hq_discount_backfill;

-- The column may already have been removed by a previous partial migration run.
SET @drop_hq_points_sql = IF(
    EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'headquarter'
          AND COLUMN_NAME = 'has_points'
    ),
    'ALTER TABLE headquarter DROP COLUMN has_points',
    'SELECT 1'
);
PREPARE drop_hq_points_stmt FROM @drop_hq_points_sql;
EXECUTE drop_hq_points_stmt;
DEALLOCATE PREPARE drop_hq_points_stmt;

DROP TABLE IF EXISTS company_loyalty;
DROP TABLE IF EXISTS company_loyalty_monthly;
