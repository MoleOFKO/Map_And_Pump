UPDATE users u
INNER JOIN system_requests approved_request
    ON approved_request.submitter_user_id = u.user_id
   AND approved_request.request_type = 'hq_registration'
   AND approved_request.status = 'approved'
INNER JOIN headquarter h
    ON h.headquarter_id = approved_request.headquarter_id
SET u.headquarter_id = h.headquarter_id,
    u.business_role_id = 3,
    u.updated_at = NOW()
WHERE u.role_id = 6
  AND (u.business_role_id IS NULL OR u.business_role_id = 0)
  AND (u.headquarter_id IS NULL OR u.headquarter_id = 0)
  AND h.headquarters_owner_id IS NOT NULL
  AND h.headquarters_owner_id <> u.user_id
  AND LOWER(TRIM(u.email)) = LOWER(TRIM(COALESCE(NULLIF(approved_request.headquarter_owner_email, ''), approved_request.sender_email)))
  AND NOT EXISTS (
      SELECT 1
      FROM system_requests other_request
      WHERE other_request.submitter_user_id = u.user_id
        AND other_request.request_type = 'hq_registration'
        AND other_request.status = 'approved'
        AND other_request.headquarter_id IS NOT NULL
        AND other_request.headquarter_id <> approved_request.headquarter_id
  );

INSERT INTO business_account_scope
    (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
SELECT u.user_id, 3, h.headquarter_id, NULL, NULL,
       CASE WHEN LOWER(COALESCE(h.status, 'pending')) IN ('active', 'approved') THEN 'active' ELSE 'pending' END
FROM users u
INNER JOIN system_requests approved_request
    ON approved_request.submitter_user_id = u.user_id
   AND approved_request.request_type = 'hq_registration'
   AND approved_request.status = 'approved'
INNER JOIN headquarter h
    ON h.headquarter_id = approved_request.headquarter_id
WHERE u.role_id = 6
  AND u.business_role_id = 3
  AND u.headquarter_id = h.headquarter_id
  AND h.headquarters_owner_id IS NOT NULL
  AND h.headquarters_owner_id <> u.user_id
  AND LOWER(TRIM(u.email)) = LOWER(TRIM(COALESCE(NULLIF(approved_request.headquarter_owner_email, ''), approved_request.sender_email)))
  AND NOT EXISTS (
      SELECT 1
      FROM system_requests other_request
      WHERE other_request.submitter_user_id = u.user_id
        AND other_request.request_type = 'hq_registration'
        AND other_request.status = 'approved'
        AND other_request.headquarter_id IS NOT NULL
        AND other_request.headquarter_id <> approved_request.headquarter_id
  )
GROUP BY u.user_id, h.headquarter_id, h.status
ON DUPLICATE KEY UPDATE
    business_role_id = VALUES(business_role_id),
    headquarter_id = VALUES(headquarter_id),
    branch_manager_user_id = NULL,
    station_id = NULL,
    status = VALUES(status);
