# Deployment and rollback

Install the Free package published with the matching GitHub release. Check its version and SHA-256 against that release before uploading. The plugin requires WordPress 6.9+, WooCommerce 10.8+, PHP 8.1+ and InnoDB accounting, order, user and usermeta tables. Network activation is unsupported; activate separately on each site.

## Before an update

1. Back up the complete database and site files, including the installed plugin version. Keep that matching pair outside the public web directory.
2. Restore the backup to a private staging site. Prevent that copy from sending customer emails, taking real payments or running production integrations.
3. Update staging first. Confirm the plugin activates, diagnostics finish, your saved rules remain correct and representative customer balances/history are preserved.
4. Verify a purchase, selected-product redemption, cancellation and partial refund using disposable accounts/orders. Check both customer history and the staff ledger. Repeat a callback only in staging and confirm it does not award or deduct twice.
5. Schedule the live update when you can inspect the result and recover promptly. Do not delete plugin data to clear an update or migration error.

## Selected-product rewards

Open Rewards → Workspace → Program settings. Keep the global product redemption default at **Cash only** when only selected products should be rewards. Enable the chosen products/variations, select their reward mode and set a positive whole-point price. Check the customer catalog after its background refresh. Enable redemption after checking the store's rules and checkout behavior.

Saved policies affect future qualifying activity. Historical awards and reversals retain their recorded amounts and beneficiaries. A cash refund is a separate WooCommerce payment operation; the points ledger records the rewards adjustment.

## Account imports and restores

Allocate fresh WordPress user IDs for new people. Do not overwrite a different person's old account to reuse its numeric ID. The plugin preserves financial history and uses private ownership records to detect conflicting account identity. Exporting/importing only selected reward tables or selected user metadata can produce an incomplete restore.

When restoring an existing customer, restore the matching WordPress account and complete rewards data from a consistent backup. If a wallet becomes unavailable after an import or restore, inspect diagnostics and the import mapping. Do not invent ledger sources, remove ownership protections or delete retained history to make a balance appear.

## Rollback

If an update fails, stop new reward transactions while investigating. Preserve the failing state and logs needed for diagnosis without publishing customer data. Restore the **matching pre-update database and site files** together using your tested host/backup procedure. Installing an older plugin over a newer database is not a verified schema rollback.

A full-site database restore can also remove unrelated orders or edits made after the backup. Reconcile those changes with the store owner before restoring production. After recovery, verify rules, representative wallets, orders and scheduled jobs before reopening reward activity.

Deactivation preserves rewards data. Uninstall preserves it by default, but the explicit data-deletion option permanently removes owned plugin data. On multisite, globally shared legacy referral metadata is retained because another site can still use it; the current site's own ownership token, tables and options are removed. Uninstall is not a rollback procedure.

## 0.6.4 transport security boundary

Keep matching code/database backups and the package hash. A code-only return to a version before 0.6.4 also removes this transport enforcement, even though schema 23 is unchanged. Treat that as a security regression requiring a deliberate incident/maintenance decision. Do not disable TLS checks to obtain a package or activate a license. Verify the intended Free/Pro edition and transport behavior after a controlled recovery.

## 0.6.5 interrupted-accounting boundary

Keep a complete matched database/plugin backup and verify the 0.6.5 installer checksum before staging the update. Schema remains 23; no identity/slug/basename migration is required. Recheck the intended exclusive Free/Pro edition and recovery queue. This update preserves historical records and does not automatically scan/reconcile older false-complete orders. Inspect relevant cancelled/refunded orders, retain audit evidence and use the supported accounting retry action. Do not remove holds or rewrite ledger rows directly. Returning only code to an older version removes the new interruption guard and is not an accounting rollback. Restore the matched database AND code in a controlled maintenance window.
