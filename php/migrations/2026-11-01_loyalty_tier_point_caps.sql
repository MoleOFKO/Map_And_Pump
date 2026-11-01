-- Migrate the original tier point thresholds to the new per-tier point caps.
-- Only replace the original defaults so administrator-customized rules survive.
UPDATE loyalty_tier_rules
SET points_threshold = 500
WHERE tier_code = 'bronze' AND points_threshold = 0;

UPDATE loyalty_tier_rules
SET points_threshold = 999
WHERE tier_code = 'silver' AND points_threshold = 500;

UPDATE loyalty_tier_rules
SET points_threshold = 1499
WHERE tier_code = 'gold' AND points_threshold = 999;

UPDATE loyalty_tier_rules
SET points_threshold = 2000
WHERE tier_code = 'platinum' AND points_threshold = 1499;

UPDATE loyalty_tier_rules
SET points_threshold = 2500
WHERE tier_code = 'diamond' AND points_threshold = 2000;
