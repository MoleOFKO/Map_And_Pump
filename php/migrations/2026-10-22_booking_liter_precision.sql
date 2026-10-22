-- Run once after 2026-10-22_core_booking_access.sql and before deploying code
-- that accepts fractional fills. Back up the database first.
-- Existing whole-liter values are preserved at two decimal places.
ALTER TABLE tanks
  MODIFY COLUMN current_l DECIMAL(12,2) NOT NULL DEFAULT 0.00;

ALTER TABLE booking
  MODIFY COLUMN filled_gas DECIMAL(10,2) NOT NULL DEFAULT 0.00;

-- This is the per-vehicle weekly fill accumulator used by quota checks.
ALTER TABLE car_license
  MODIFY COLUMN filled_gas DECIMAL(10,2) NOT NULL DEFAULT 0.00;
