# Rewards & Referrals

**0.6.0-rc.9 — staging candidate.** Author: [Abdul Hameed](https://www.linkedin.com/in/abdul-hameed-website-developer/). This package has not been approved for unrestricted market or production deployment. See `engineering/final-ship/CONTINUATION-REPORT.md` in the separate development bundle for executed checks, external blockers and exact supported test combinations.

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

Minimum baseline: WordPress 6.9, WooCommerce 10.8, PHP 8.1. Use a currently supported upstream hosting stack; active WooCommerce order storage and plugin accounting tables must use InnoDB. Multisite network activation is not supported. No certified third-party subscriptions, bundles or currency conversion integration is advertised.

1. Back up the complete database and matching plugin files. Test the upgrade on a staging clone.
2. Upload the separate Free or Pro installation ZIP, activate WooCommerce first and open Rewards in the WordPress sidebar.
3. Review Workspace > Program settings, then Customers > Reward catalog. Keep redemption disabled until your policy and selected products are verified.
4. For legacy upgrades, stop old workers and checkout/registration while conversion/backfills run. Preserve original ledger data. Review migration/reconstruction status and reconcile wallets before reopening. An opening legacy balance is preserved as non-expiring provenance rather than inventing historical expiry dates.
5. Roll back with the matching database AND code backup; never uninstall or replace code alone as an accounting rollback.

Deactivation retains data. Uninstall also retains it by default. The explicit full-deletion setting removes plugin-owned data only when uninstall runs. WordPress privacy exports cover copied identity and rewards data; financial erasure reports retention. Merchants remain responsible for retention/consent policy. `rr_referral_tracking_allowed` is a consent integration hook, not an automatic legal-compliance system.

## Customer and editor integration

My Account > Rewards provides wallet, referrals, selected reward catalogue, activity and redemption history. Shortcodes: `[rewards_wallet]`, `[rewards_referral_link]`, `[rewards_referral_stats]`, `[rewards_referral_dashboard]`, `[rewards_transaction_history]`, `[rewards_catalog]`, `[rewards_redemption_history]`, `[rewards_summary]`. A dynamic block and Elementor widget expose the same components. No theme/core files are edited.

## Administration

Workspace, Customers, Operations and Insights & system organize the dashboard. Inner sections use AJAX navigation with history support. Settings show a fixed save bar only when changed. Tables use bounded search/pagination; dropdowns retain native submitted values and keyboard controls. Locale-ready strings/POT, focus states and RTL styles are included; complete translation, accessibility and theme certification remain separate verification gates.

See `docs/DEPLOYMENT-ROLLBACK.md`, `docs/SECURITY-PERFORMANCE.md` and the current development release report. Older reports are historical evidence and do not certify this candidate. Tests, private configuration and credentials are excluded from installation archives.
