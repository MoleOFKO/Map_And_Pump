# MAP & PUMP Database Compatibility Report

**Baseline checked:** the supplied `db.sql` dump dated 2026-09-19 and the PHP role code in this repository.

## Executive Summary

The database covers most of the tables and columns used by Customer, Staff, Branch Manager, HQ, Admin, Moderator, authentication, and company registration flows.

There is one confirmed cross-role blocker:

> `stations` is defined in `db.sql` as a dummy view that returns one hard-coded row. Several roles use `stations` as the real station table, and HQ test code tries to insert and update it.

The live schema uses `branches` as the real station table. The application must standardize on `branches`, or the `stations` compatibility view and all write paths must be redesigned before all roles can be considered compatible.

## Role-by-Role Result

| Role / area | Result | Notes |
|---|---|---|
| Login and role switching | Mostly meets | `users`, `roles`, `current_role_id`, `business_role_id`, `last_login_at`, and account-deletion columns exist in the dump. |
| Customer | Mostly meets | Customer identity, licenses, cars, bookings, usage history, feedback, invitations, and notifications exist. Customer pages also read the broken `stations` view. |
| Staff | Mostly meets | `staff.user_id`, `branches.manager_user_id`, bookings, usage history, feedback, and users exist. The optional `staff.email` column is absent, but the code detects that and falls back to `users.email`. |
| Branch Manager | Meets the documented backend changes | `notifications.read_at`, `usage_history` cost/litre fields, inventory, tanks, deliveries, pumps, reports, and branch scope tables exist. |
| HQ | Partially meets | HQ management tables exist, but most HQ station queries use the broken `stations` view. HQ invitation schema is present. |
| Admin | Partially meets | Admin station counts/details use the broken `stations` view. The user, HQ, request, report, log, and action tables exist. |
| Moderator | Partially meets | Moderator station counts use the broken `stations` view. The user, HQ, request, report, log, and notification tables exist. |
| Company registration | Meets schema requirements | `system_requests` and `hq_receipt` contain the columns used by company registration. |

## Confirmed Required Fix

### 1. Replace the dummy `stations` implementation

The dump currently creates this kind of object:

```sql
CREATE VIEW stations AS
SELECT 1 AS station_id, 1 AS station_name, ...;
```

That does not represent the real stations in `branches`. It causes incorrect counts and empty or fake station details. Insert and update operations in `php/HQ/test.php` are also not valid against this dummy view.

Affected role surfaces include:

- `php/Admin(WebOwner)/admin_dashboard.php`
- `php/Admin(WebOwner)/hq_details.php`
- `php/Admin(WebOwner)/report_action.php`
- `php/Admin(WebOwner)/view_report.php`
- `php/Moderators(UnderAdmin)/moderator_dashboard.php`
- `php/Moderators(UnderAdmin)/view_report(M).php`
- `php/HQ/account.php`
- `php/HQ/dashboard.php`
- `php/HQ/invite_account.php`
- `php/HQ/pricing.php`
- `php/HQ/test.php`
- Customer and Staff pages that display station names

**Recommended fix:** use `branches` as the canonical table and update station queries to map the names explicitly:

| Legacy `stations` field | Canonical `branches` field |
|---|---|
| `station_id` | `branch_id` |
| `station_name` | `branch_name` |
| `station_address` | `address` |
| `station_township` | `township` |
| `business_user_id` | `manager_user_id` |
| `status` | `status` |
| `created_at` | `created_at` |
| `updated_at` | `updated_at` |

Also update any write operation currently targeting `stations`, especially `php/HQ/test.php`, to write to `branches` and supply the required `branch_code` and `city` values.

## Compatibility Gaps That Are Not Immediate Blockers

### `staff.email` is absent

The dump's `staff` table has no `email` column. `php/BranchManager/manage-staff.php` and `php/Customer/respond_invitation.php` already check for the column and fall back safely, so the current flow can work. Keep staff email in `users.email` unless a separate staff email field is intentionally required.

### Notification read index is optional

The dump already contains `notifications.read_at`, so the Branch Manager migration is not needed for this database. The composite index on `(recipient_user_id, read_at)` is absent, but this is a performance improvement rather than a functional requirement.

### Feedback replies need a schema decision

`feedback_replies.staff_id` represents a staff-table identity, while a Branch Manager is a users-table identity. The Branch Manager flow does not currently reply to feedback, so no change is required for the documented use cases. If manager replies are added later, use a clear `replied_by_user_id` design rather than storing a manager user ID in `staff_id`.

## Database Objects Verified in `db.sql`

The following required objects are present:

- Identity and roles: `users`, `roles`, `headquarter`, `branches`
- Scope and invitations: `business_account_scope`, `station_business_scope`, `invitations`, `hq_account_invitations`
- Customer operations: `user_license`, `car_license`, `user_car`, `booking`, `usage_history`, `feedback`, `feedback_replies`
- Branch operations: `staff`, `pumps`, `tanks`, `deliveries`, `station_oil_inventory`
- Administration and reporting: `system_requests`, `report`, `log`, `notifications`, `hq_receipt`

## Final Decision

Do not apply the Branch Manager notification migration to the supplied database because `notifications.read_at` already exists.

Before claiming that the database supports **all roles**, fix the `stations` versus `branches` mismatch. After that, run role smoke tests for login, station listing, HQ invitations, customer booking/history, staff verification, Branch Manager completion, Admin station metrics, and Moderator station metrics.
