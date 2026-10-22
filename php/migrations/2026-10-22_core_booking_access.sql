-- Core booking/access schema. Run once before deploying the matching PHP code.
-- Existing QR payloads are used only to backfill legacy requested liters.
ALTER TABLE booking
  ADD COLUMN requested_liters DECIMAL(10,2) NULL AFTER oil_type_id;

UPDATE booking
SET requested_liters = CAST(
  REPLACE(SUBSTRING_INDEX(qr_code, '|', -1), 'L', '') AS DECIMAL(10,2)
)
WHERE requested_liters IS NULL
  AND qr_code REGEXP '\\|[0-9]+(\\.[0-9]+)?L$';

-- Invalid legacy active bookings cannot safely make a stock guarantee.
UPDATE booking
SET status = 'expired'
WHERE status IN ('notfilled', 'waiting', 'serving')
  AND (requested_liters IS NULL OR requested_liters <= 0);

CREATE TABLE booking_reservations (
  booking_id INT NOT NULL,
  station_id INT NOT NULL,
  oil_type_id INT NOT NULL,
  requested_liters DECIMAL(10,2) NOT NULL,
  price_per_litre DECIMAL(12,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (booking_id),
  KEY idx_booking_reservations_stock (station_id, oil_type_id),
  CONSTRAINT fk_booking_reservation_booking FOREIGN KEY (booking_id)
    REFERENCES booking (booking_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO booking_reservations (booking_id, station_id, oil_type_id, requested_liters, price_per_litre)
SELECT b.booking_id, b.station_id, b.oil_type_id, b.requested_liters,
       COALESCE((SELECT i.price_per_litre FROM station_oil_inventory i
                 WHERE i.station_id = b.station_id AND i.oil_type_id = b.oil_type_id
                   AND i.updated_at <= b.created_at
                 ORDER BY i.updated_at DESC LIMIT 1), 0)
FROM booking b
WHERE b.requested_liters > 0
  AND b.status IN ('notfilled', 'waiting', 'serving')
  AND EXISTS (SELECT 1 FROM station_oil_inventory i
              WHERE i.station_id = b.station_id AND i.oil_type_id = b.oil_type_id
                AND i.price_per_litre > 0 AND i.updated_at <= b.created_at);

UPDATE booking b
SET b.status = 'expired'
WHERE b.status IN ('notfilled', 'waiting', 'serving')
  AND NOT EXISTS (SELECT 1 FROM booking_reservations r WHERE r.booking_id = b.booking_id);

ALTER TABLE booking MODIFY requested_liters DECIMAL(10,2) NOT NULL DEFAULT 0;
-- If a deployed database reports duplicate references here, resolve those
-- legacy collisions first; new references must be unique at the DB boundary.
-- Preflight before this migration: SELECT qr_refrence_code, COUNT(*) AS n
-- FROM booking GROUP BY qr_refrence_code HAVING COUNT(*) > 1;
ALTER TABLE booking ADD UNIQUE KEY uq_booking_qr_reference (qr_refrence_code);

CREATE TABLE booking_partial_offers (
  booking_id INT NOT NULL,
  offered_liters DECIMAL(10,2) NOT NULL,
  status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  offered_by INT NOT NULL,
  offered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  answered_at DATETIME NULL,
  PRIMARY KEY (booking_id),
  CONSTRAINT fk_partial_offer_booking FOREIGN KEY (booking_id)
    REFERENCES booking (booking_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE station_shortfall_coupons (
  coupon_id BIGINT NOT NULL AUTO_INCREMENT,
  booking_id INT NOT NULL,
  user_id INT NOT NULL,
  issuing_station_id INT NOT NULL,
  headquarter_id INT NOT NULL,
  face_value_mmk DECIMAL(12,2) NOT NULL,
  status ENUM('available','redeemed','expired') NOT NULL DEFAULT 'available',
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  redeemed_at DATETIME NULL,
  redeemed_station_id INT NULL,
  redemption_value_mmk DECIMAL(12,2) NULL,
  PRIMARY KEY (coupon_id),
  UNIQUE KEY uq_shortfall_coupon_booking (booking_id),
  KEY idx_shortfall_coupon_user_status (user_id, status, expires_at),
  CONSTRAINT fk_shortfall_coupon_booking FOREIGN KEY (booking_id)
    REFERENCES booking (booking_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE station_coupon_redemptions (
  redemption_id BIGINT NOT NULL AUTO_INCREMENT,
  coupon_id BIGINT NOT NULL,
  usage_id INT NOT NULL,
  station_id INT NOT NULL,
  applied_mmk DECIMAL(12,2) NOT NULL,
  redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (redemption_id),
  UNIQUE KEY uq_coupon_redemption (coupon_id),
  UNIQUE KEY uq_coupon_usage (usage_id),
  CONSTRAINT fk_coupon_redemption_coupon FOREIGN KEY (coupon_id)
    REFERENCES station_shortfall_coupons (coupon_id),
  CONSTRAINT fk_coupon_redemption_usage FOREIGN KEY (usage_id)
    REFERENCES usage_history (usage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
