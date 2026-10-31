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

CREATE TABLE IF NOT EXISTS loyalty_discount_account (
    account_id TINYINT UNSIGNED NOT NULL,
    balance_mmk DECIMAL(14,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_discount_payouts (
    payout_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payout_month CHAR(7) NOT NULL,
    amount_mmk DECIMAL(14,2) NOT NULL,
    note VARCHAR(500) DEFAULT NULL,
    processed_by_user_id INT NOT NULL,
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payout_id),
    KEY idx_loyalty_discount_payout_month (payout_month, processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

ALTER TABLE headquarter DROP COLUMN has_points;
DROP TABLE IF EXISTS company_loyalty;
DROP TABLE IF EXISTS company_loyalty_monthly;
