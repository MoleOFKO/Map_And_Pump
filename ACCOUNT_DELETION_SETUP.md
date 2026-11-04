# HQ Account Deletion Cleanup

HQ account deletion is a soft-delete workflow:

1. The HQ owner requests deletion from `php/HQ/profile.php`.
2. The account changes to `pending_deletion`; its HQ record stores the request time and a deadline 30 days later.
3. The owner can sign in during the recovery period and restore the account from the HQ profile page.
4. `php/cleanup_account_deletions.php` permanently removes accounts whose deadline has passed.

Run the cleanup command once per day from the project directory:

```text
php php/cleanup_account_deletions.php
```

On Windows, configure this command in Task Scheduler with a daily trigger. On Linux, configure a daily cron entry. The login flow also runs the cleanup as a fallback, but a daily scheduled task is recommended for deletion to happen without a login.

For databases that still have the legacy `users.branch_id` column and have not already run it, apply `php/migrations/2026-10-30_remove_users_branch_id_move_hq_deletion.sql` before deploying the updated code. It moves existing staff/manager assignments and HQ deletion dates before dropping the legacy user columns. Skip it if `users.branch_id` is already absent; the current `db.sql` does not include that column.

Then apply `php/migrations/2026-11-04_remove_users_headquarter_id.sql` once to copy any remaining user-to-HQ assignments into `business_account_scope` and remove `users.headquarter_id`. The migration is safe to run when that column is already absent.
