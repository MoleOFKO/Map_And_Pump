CREATE TABLE IF NOT EXISTS hq_account_invitations (
    invitation_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    headquarter_id INT NOT NULL,
    invited_user_id INT NOT NULL,
    invited_email VARCHAR(255) NOT NULL,
    business_role_id INT NOT NULL,
    station_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    notification_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (invitation_id),
    KEY idx_hq_invitation_user_status (invited_user_id, status),
    KEY idx_hq_invitation_hq (headquarter_id),
    CONSTRAINT fk_hq_invitation_hq FOREIGN KEY (headquarter_id) REFERENCES headquarter (headquarter_id) ON DELETE CASCADE,
    CONSTRAINT fk_hq_invitation_user FOREIGN KEY (invited_user_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_hq_invitation_role FOREIGN KEY (business_role_id) REFERENCES roles (role_id) ON DELETE NO ACTION,
    CONSTRAINT fk_hq_invitation_station FOREIGN KEY (station_id) REFERENCES stations (station_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;