# HQ Account Deletion Cleanup

HQ account deletion is a soft-delete workflow:

1. The HQ owner requests deletion from `php/HQ/profile.php`.
2. The account changes to `pending_deletion` and receives a deadline 30 days later.
3. The owner can sign in during the recovery period and restore the account from the HQ profile page.
4. `php/cleanup_account_deletions.php` permanently removes accounts whose deadline has passed.

Run the cleanup command once per day from the project directory:

```text
php php/cleanup_account_deletions.php
```

On Windows, configure this command in Task Scheduler with a daily trigger. On Linux, configure a daily cron entry. The login flow also runs the cleanup as a fallback, but a daily scheduled task is recommended for deletion to happen without a login.
