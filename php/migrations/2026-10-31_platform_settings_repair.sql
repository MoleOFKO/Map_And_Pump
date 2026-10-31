CREATE TABLE IF NOT EXISTS platform_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO platform_settings (setting_key, setting_value) VALUES
    ('loyalty_points_per_liter', '1'),
    ('loyalty_pool_enforced', '1'),
    ('hq_registration_fee_mmk', '2999999'),
    ('hq_package_mmk_per_station_month', '37000')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
