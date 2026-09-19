# MAP & PUMP — Branch Manager backend changes

## 1. Notifications: real read-state  (`_helpers.php`, `api_dismiss_notification.php`)
- `notifications` table had no read column. Added `read_at` (auto-added on first
  load; also in `migrations/2026-09-19_notifications_read_at.sql`).
- New helpers in `_helpers.php`:
  `notif_has_read_column`, `notif_kind`, `notif_unread_count`, `notif_recent`,
  `notif_mark_read`, `notif_mark_all_read`, `notif_create`,
  `hq_recipient_ids`, `notif_low_stock_scan`.
- `api_dismiss_notification.php` now actually persists read state (single + all)
  and returns `{ ok, updated, unread }`.

## 2. Dashboard  (`dashboard.php`)
- Alerts panel query/keys fixed (was reading `kind/title/body/notif-id` that the
  query never returned → showed blank). Now driven by `notif_recent()`.
- Notification popup is now data-driven (was hardcoded HTML).
- "Clear All" + click-to-dismiss are wired to `api_dismiss_notification.php`.
- Low-stock tanks are surfaced as notifications on load (deduped, 6h window).

## 3. Booking slots  (`booking-slot.php`)
- Bell unread badge uses the real unread count.
- **`complete` now records a `usage_history` row** (feeds the dashboard avg-wait
  KPI + customer history), looks up the unit price from `station_oil_inventory`,
  computes `total_cost`, and draws down station inventory.
- Optional POST param **`filled_liters`** (float) — pass the actual litres
  dispensed; defaults to 0 when not supplied.

## 4. Report to HQ  (`report-hq.php`)
- Filing a report now creates a `notifications` row for each HQ recipient
  (HQ owner + role_id=3 users under the same headquarter).

## 5. Manage oil  (`manage-oil.php`)
- Low-stock scan runs after a delivery.
- Removed an unreachable, broken legacy refill handler (referenced a
  non-existent `stock_logs` table and wrong tank columns). Real refills use the
  `add_delivery` action.

## Notes / not done (out of documented use-case scope)
- **Feedback reply**: `feedback_replies.staff_id` is an FK to the `staff` table,
  but a Branch Manager is a `users` row, not a `staff` row — so manager replies
  don't fit that table cleanly. The flowchart lists only "View Feedback", so
  reply was intentionally left out. Say the word if you want it (would need a
  small schema decision on who "owns" the reply).
