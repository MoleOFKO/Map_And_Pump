-- Scope every business account and station to its HQ, and keep fuel pricing append-only.
-- Run this once after deploying the PHP changes.

INSERT INTO oil_types (oil_name)
SELECT 'Octane 92' WHERE NOT EXISTS (SELECT 1 FROM oil_types WHERE LOWER(oil_name) = LOWER('Octane 92'));
INSERT INTO oil_types (oil_name)
SELECT 'Octane 95' WHERE NOT EXISTS (SELECT 1 FROM oil_types WHERE LOWER(oil_name) = LOWER('Octane 95'));
INSERT INTO oil_types (oil_name)
SELECT 'Disel' WHERE NOT EXISTS (SELECT 1 FROM oil_types WHERE LOWER(oil_name) IN (LOWER('Disel'), LOWER('Diesel')));
INSERT INTO oil_types (oil_name)
SELECT 'Premium Disel' WHERE NOT EXISTS (SELECT 1 FROM oil_types WHERE LOWER(oil_name) IN (LOWER('Premium Disel'), LOWER('Premium Diesel')));

INSERT INTO station_business_scope (station_id, headquarter_id, branch_manager_user_id)
SELECT s.station_id, COALESCE(u.headquarter_id, bas.headquarter_id), s.business_user_id
FROM stations s
LEFT JOIN users u ON u.user_id = s.business_user_id
LEFT JOIN business_account_scope bas ON bas.user_id = s.business_user_id
WHERE COALESCE(u.headquarter_id, bas.headquarter_id) IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM station_business_scope existing
      WHERE existing.station_id = s.station_id
  );

INSERT INTO business_account_scope (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
SELECT s.business_user_id, 4, sbs.headquarter_id, NULL, s.station_id, COALESCE(u.status, 'active')
FROM stations s
INNER JOIN station_business_scope sbs ON sbs.station_id = s.station_id
INNER JOIN users u ON u.user_id = s.business_user_id
WHERE NOT EXISTS (
    SELECT 1 FROM business_account_scope existing
    WHERE existing.user_id = s.business_user_id
);

UPDATE users u
INNER JOIN business_account_scope bas ON bas.user_id = u.user_id
SET u.headquarter_id = COALESCE(u.headquarter_id, bas.headquarter_id),
    u.branch_id = COALESCE(u.branch_id, bas.station_id)
WHERE COALESCE(u.business_role_id, u.role_id) IN (4, 5);

INSERT INTO tanks (branch_id, fuel_code, fuel_name, capacity_l, current_l, alert_pct, is_enabled)
SELECT s.station_id, seed.fuel_code, seed.fuel_name, seed.capacity_l, 0, 25, 1
FROM stations s
CROSS JOIN (
    SELECT 'p92' AS fuel_code, 'Petrol 92' AS fuel_name, 15000 AS capacity_l
    UNION ALL SELECT 'p95', 'Petrol 95', 10000
    UNION ALL SELECT 'dsl', 'Diesel', 12000
    UNION ALL SELECT 'pdsl', 'Premium Diesel', 12000
) seed
WHERE NOT EXISTS (
    SELECT 1 FROM tanks t
    WHERE t.branch_id = s.station_id AND t.fuel_code = seed.fuel_code
);

INSERT INTO station_oil_inventory (station_id, oil_type_id, price_per_litre, is_active, current_amount_liters, updated_at)
SELECT s.station_id, ot.oil_type_id, 0, 1, 0, NOW()
FROM stations s
CROSS JOIN (
    SELECT oil_type_id, oil_name FROM oil_types
    WHERE oil_name IN ('Octane 92', 'Octane 95', 'Disel', 'Diesel', 'Premium Disel', 'Premium Diesel')
) ot
WHERE NOT EXISTS (
    SELECT 1 FROM station_oil_inventory soi
    WHERE soi.station_id = s.station_id AND soi.oil_type_id = ot.oil_type_id
);

CREATE INDEX idx_station_scope_hq_station ON station_business_scope (headquarter_id, station_id);
CREATE INDEX idx_business_scope_hq_role_status ON business_account_scope (headquarter_id, business_role_id, status);
CREATE INDEX idx_station_inventory_latest ON station_oil_inventory (station_id, oil_type_id, inventory_id);
