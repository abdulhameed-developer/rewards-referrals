# Rewards & Referrals

**0.6.0.** Author: [Abdul Hameed](https://www.linkedin.com/in/abdul-hameed-website-developer/). The WordPress directory display name is Rewards & Referrals by Abdul Hameed; the installed folder, text domain, public APIs and database identifiers remain rewards-referrals. Verification scope and external integration limits are documented on the [public releases page](https://github.com/abdulhameed-developer/rewards-referrals/releases). This release does not claim universal gateway, theme or accessibility certification.

## Program controls

Purchase points use configurable rates and floor, nearest/half-up or ceiling rounding. Direct referral commission is single-level: a customer's own referrals do not reward that customer's referrer. Eligibility can be lifetime, first qualifying order/item, a bounded number, a date range or a time window. Tax, shipping, coupons, earning delays, expiry, caps and product/customer policies remain configurable. Saved rule snapshots preserve original award/refund calculations.

Points-enabled products and individual variations support points only, cash or points, or points plus cash. Keep the global product default at Cash only/Disabled and assign positive whole-point prices to selected rewards. Enable redemption separately. The customer catalogue indexes eligible published/visible products and child variations in background batches; checkout always validates current policy and balance again.

## Accounting and recovery

An idempotent source ledger, transactional wallet counters, point lots and FIFO allocations track available, pending, reserved, spent, expired and debt amounts. Cancellations and itemized refunds reverse the original beneficiary and historical amounts. Large wallet reconstruction, legacy provenance conversion, due maturity and expiry use bounded workers. Failed financial transitions retain recovery holds; an administrator can inspect these under Insights & system. A hold must not be deleted merely to unlock spending.

Delayed paid confirmations and failed-payment releases have durable recovery intents. Orders needing accounting recovery pause fulfillment. Closed reward reservations cannot be paid again through order-pay: start a fresh checkout. Unpaid BACS orders remain subject to the configured reservation timeout; a late settlement after release requires merchant review. Provider-specific delayed/webhook/chargeback behavior still needs authorized gateway verification.

## Free and Pro

Free has no product-count cap and works without a Freemius account connection. It includes core earning, direct referrals, wallets, redemption, cancellation/refund accounting, privacy exports, ledger CSV, standard notifications, audit browsing, rule comparison, wallet reconciliation, redacted diagnostics, customer schedules and statements.

Pro adds scheduled campaign multipliers, campaign preflight/overlap review, filtered summary reports and custom notification templates. Premium implementation is removed from the Free archive. Switching editions preserves financial history and saved settings. Future Free awards ignore premium multipliers; existing credits retain their snapshots. Pro requires rights granted by the official Freemius SDK; no entitlement bypass is included. Local GPL code cannot be made tamper-proof.

## Installation and upgrades

Minimum baseline: WordPress 6.9, WooCommerce 10.8, PHP 8.1. Use a currently supported upstream hosting stack; active WooCommerce order storage, WordPress users/usermeta and plugin accounting tables must use InnoDB. Multisite network activation is not supported. No certified third-party subscriptions, bundles or currency conversion integration is advertised.

1. Back up the complete database and matching plugin files. Test the upgrade on a staging clone.
2. Upload the separate Free or Pro installation ZIP, activate WooCommerce first and open Rewards in the WordPress sidebar.
3. Review Workspace > Program settings, then Customers > Reward catalog. Keep redemption disabled until your policy and selected products are verified.
4. For legacy upgrades, stop old workers and checkout/registration while conversion/backfills run. Preserve original ledger data. Review migration/reconstruction status and reconcile wallets before reopening. An opening legacy balance is preserved as non-expiring provenance rather than inventing historical expiry dates.
5. Roll back with the matching database AND code backup; never uninstall or replace code alone as an accounting rollback.

Deactivation retains data. Uninstall also retains it by default. The explicit full-deletion setting removes plugin-owned data only when uninstall runs. On multisite, unscoped legacy referral metadata is retained because another site may still need it; only the current site's ownership token, tables and options are removed. WordPress privacy exports cover copied identity and rewards data; financial erasure reports retention. Merchants remain responsible for retention/consent policy. `rr_referral_tracking_allowed` is a consent integration hook, not an automatic legal-compliance system.

## Customer and editor integration

My Account > Rewards provides wallet, referrals, selected reward catalogue, activity and redemption history. Shortcodes: `[rewards_wallet]`, `[rewards_referral_link]`, `[rewards_referral_stats]`, `[rewards_referral_dashboard]`, `[rewards_transaction_history]`, `[rewards_catalog]`, `[rewards_redemption_history]`, `[rewards_summary]`. A dynamic block and Elementor widget expose the same components. No theme/core files are edited.

## Administration

Workspace, Customers, Operations and Insights & system organize the dashboard. Inner sections use AJAX navigation with history support. Settings show a fixed save bar only when changed. Tables use bounded search/pagination; dropdowns retain native submitted values and keyboard controls. Locale-ready strings/POT, focus states and RTL styles are included; complete translation, accessibility and theme certification remain separate verification gates.

See the public [deployment and rollback guide](https://github.com/abdulhameed-developer/rewards-referrals/blob/main/docs/DEPLOYMENT-ROLLBACK.md), [security and verification scope](https://github.com/abdulhameed-developer/rewards-referrals/blob/main/docs/SECURITY-PERFORMANCE.md), and [release verification guide](https://github.com/abdulhameed-developer/rewards-referrals/blob/main/docs/RELEASE-VERIFICATION.md). Tests, private configuration and credentials are excluded from installation archives.


## Account imports and restore

Schema 23 atomically records ownership of existing financial and referral identities. Customer imports must preserve the original account and ownership metadata or allocate a fresh ID; a different person cannot inherit retained history by reusing a number. Conflicting reward accounts remain unavailable while their history is preserved. Ordinary profile, email and password changes preserve ownership. A complete matching database/code backup also preserves ownership proofs. Replacing only the code with a pre-guard version is not a safe rollback.
