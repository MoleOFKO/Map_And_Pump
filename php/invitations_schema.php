<?php

if (!function_exists('map_pump_ensure_invitation_schema')) {
    function map_pump_ensure_invitation_schema(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS invitations (
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
                KEY idx_invitations_user_status (invited_user_id, status),
                KEY idx_invitations_hq (headquarter_id),
                CONSTRAINT fk_invitations_hq FOREIGN KEY (headquarter_id) REFERENCES headquarter (headquarter_id) ON DELETE CASCADE,
                CONSTRAINT fk_invitations_user FOREIGN KEY (invited_user_id) REFERENCES users (user_id) ON DELETE CASCADE,
                CONSTRAINT fk_invitations_role FOREIGN KEY (business_role_id) REFERENCES roles (role_id) ON DELETE NO ACTION,
                CONSTRAINT fk_invitations_station FOREIGN KEY (station_id) REFERENCES stations (station_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}