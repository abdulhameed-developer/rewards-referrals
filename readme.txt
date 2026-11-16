=== Rewards & Referrals by Abdul Hameed ===
Contributors: codian
Tags: woocommerce, rewards, loyalty, referrals, points
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.6.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Customer loyalty points, direct referral rewards, and product redemption for WooCommerce.

== Description ==

Create a loyalty program with customer wallets, direct referral commissions, product reward prices, campaigns, and an auditable points ledger.

* Purchase earning rules, delayed availability, and point expiration.
* Cash only, points only, cash or points, and points plus cash product modes.
* Classic WooCommerce and Store API checkout integration.
* My Account rewards dashboard and customer-facing shortcodes.
* Administrative reports, settings transfer, and privacy-tool integration.
* Program check with catalogue setup guidance and purchase examples that create no order or points.

Version 0.6.1 improves customer-facing text contrast while preserving the existing loyalty program and durable account ownership protection. WordPress 6.9+, WooCommerce 10.8+, PHP 8.1+ and InnoDB accounting, order, user and usermeta tables are required. Exact tested combinations and known limits are recorded with each release at https://github.com/abdulhameed-developer/rewards-referrals/releases and in the public verification guide at https://github.com/abdulhameed-developer/rewards-referrals/blob/main/docs/RELEASE-VERIFICATION.md . These minimum versions are not a promise for every combination. Network activation is not supported; activate separately on each site.

Free includes core points, direct referrals, wallets, redemption, accounting exports and privacy tools, without a product-count limit. Pro adds scheduled earning campaigns, filtered summary reports and custom notification templates.

The bundled Freemius SDK provides optional account connection in Free, and license activation, paid downloads and updates in Pro. Free can be used without connecting an account. When connecting or activating a license, the SDK communicates with Freemius and shares the information shown on its consent screen. See https://freemius.com/privacy/ and https://freemius.com/terms/ . Customer rewards balances and order accounting are stored locally; our integration does not upload the ledger to Freemius. Optional customer email notifications use the site's WordPress mail configuration. Referral attribution uses a first-party, HTTP-only br_ref cookie with SameSite=Lax and a configurable lifetime. Store owners are responsible for appropriate customer notices and consent configuration.

Accounting data is retained on deactivation and, by default, on uninstall. Explicit data deletion can be enabled under Tools & diagnostics. On multisite, globally shared legacy referral metadata is retained for other sites; site-specific tables, options and ownership tokens are removed. Private account-ownership tokens and retained identity markers protect financial records when numeric customer IDs are reused. These security tokens are not included in customer statements. WordPress privacy export includes the customer's ledger; privacy erasure reports the accounting records retained for balance and audit integrity.

== Source and build tools ==

The Freemius SDK's corresponding source is pinned at:
https://github.com/Freemius/wordpress-sdk/tree/0875c4ee3c941572f44d60cb2a687c54295db3d7

Its compiled pricing application and build tools are available at:
https://github.com/Freemius/pricing-page/tree/e698ea512a55839dda26be4a1fb7341e675d7053
https://github.com/Freemius/pricing-page/blob/e698ea512a55839dda26be4a1fb7341e675d7053/CONTRIBUTING.md
https://github.com/Freemius/pricing-page/blob/e698ea512a55839dda26be4a1fb7341e675d7053/webpack.config.js

The source and runtime jquery.form.js adapters are independently authored GPL-3.0-or-later replacements. Other SDK notices and full dependency attributions are retained in THIRD-PARTY-NOTICES.txt. The pricing bundle is unchanged; upstream supplies dependency ranges rather than a reproducible lockfile.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate Rewards & Referrals.
3. Open Rewards > Workspace > Program settings.
4. Keep Default product redemption mode at Cash only for a selected-products catalog.
5. Enable selected products and set a positive whole-number points price.
6. Verify checkout, cancellation, and refund behavior on staging before enabling Point redemption.

== Frequently Asked Questions ==

= Why does an 8.99 purchase earn 8 points? =
Round down is the default. Open Rewards > Workspace > Program settings > Purchase rewards > Earned points rounding. At one point per currency unit, Round to nearest gives 9 points for 8.99, while Round up also gives 9 for 8.01. Rounding applies per eligible order line including its quantity, and to multiplier results and referral commissions. Saved changes affect future awards; historical credits/refunds retain their original amounts.

= Why does a product say Inherit? =
It follows Default product redemption mode in Program settings. Variations inherit their parent unless explicitly overridden. Keep the global default at Cash only to offer only selected products as rewards.

= Why is a product absent from the customer catalog? =
Enable Point redemption and set a positive whole-point price with a points-enabled mode. Published, visible, in-stock simple products and eligible individual variations appear after the background catalogue refresh. An eligible child can appear even when its parent is cash only; its parent must still be published and visible. Checkout rechecks current eligibility. Keep the store default at Cash only to show selected rewards.

= Why is an imported rewards account awaiting ownership review? =
An import may have assigned a previously used customer ID to a different person. Earlier reward and referral history is preserved and cannot be claimed by the replacement account. Allocate a fresh customer ID or restore the original account with its matching ownership records. Ordinary name, email and password updates preserve ownership. Restore matching plugin files, database and user metadata together when recovering a backup.

= Does deactivation delete points? =
No. Accounting history is preserved. Full deletion requires the explicit uninstall data-deletion option.

= Which shortcodes are available? =
[rewards_wallet], [rewards_referral_link], [rewards_referral_stats], [rewards_referral_dashboard], [rewards_transaction_history], [rewards_catalog], [rewards_redemption_history], and [rewards_summary].

== Changelog ==

= 0.6.1 =
* Improve contrast for customer explanations, referral labels, reward availability, empty states, table headings and pagination.
* Preserve the existing layout, earning and redemption rules, accounting data, and schema 23.

= 0.6.0 =
* Stable successor to the 0.6.0 release-candidate series; all Free and Pro capabilities retained.
* Atomically bind rewards and referral history to its account owner; preserve and quarantine conflicting imported IDs.
* Protect customer balances, histories, statements, delayed jobs and refund accounting from identity reuse.
* Keep commission, referral and reservation maintenance progressing past protected historical accounts without granting financial access.
* Record missing-account migration and reconstruction failures safely and prevent blocked checkpoints from starving healthy jobs.
* Preserve historical data while upgrading to schema 23 and retain the existing plugin basename and APIs.
* Include the compatible independently authored form adapter and corresponding-source disclosures.


= 0.6.0-rc.9 =
* Keep unattributable point lots isolated when an import reuses a deleted customer ID.
* Validate lot source ownership before consumption, expiry and returns.
* Replace a separately licensed upstream form helper with a compatible independently authored adapter.
* Add explicit corresponding-source and build-tool links for bundled pricing code.
* Preserve existing features and schema 22; pre-release verification remains documented.


= 0.6.0-rc.8 =
* Require successful relationship audits before attribution commits.
* Report notification retry read/write/audit/commit failures and clean up throwing transactions.
* Preserve truthful committed-state messages when post-save integrations fail.
* Include verified third-party notices and pinned corresponding-source references.
* Staging candidate; commercial and compatibility acceptance remains open.


= 0.6.0-rc.7 =
* Staging candidate: verified product policy metadata reads fail closed during database outages.
* Transactional configuration and referral approval saves with checked audit results.
* Explicit report/history read errors and truthful partial catalog-save failures.
* Historical redemption usage read failures no longer appear complete.
* Retains prior features and schema 22; unrestricted commercial release acceptance remains open.


= 0.6.0-rc.6 =
* Preserve saved policy on failed reads, writes and audit recording; align policy lock ordering.
* Show customer schedule storage errors and make history tables keyboard focusable.
* Staging candidate; broader release verification remains pending.

= 0.6.0-rc.5 =
* Stop purchase and commission awards when customer-cap history cannot be read; retry without exceeding saved limits.
* Keep refund recovery pending when reservation reads fail instead of marking incomplete restoration as complete.
* Schedule cancellation and refund retries even when initial beneficiary or recovery-intent reads fail.
* Validate a coherent saved policy before awards; reject failed or malformed policy reads instead of enabling defaults.
* Preserve first-touch and manual referral approval when settings are unreadable; retain bounded intent for verified recovery.
* Pro campaign policy reads preserve configured multipliers and propagate storage errors instead of awarding a fallback rate.
* Customer activity explains earned-point reversals versus restored redemption points and reports unavailable history without a misleading empty state.
* Free Program check explains catalogue setup and previews purchase calculations using saved rules, product overrides, rounding and optional customer history.
* Stronger development static analysis and scanner-result validation; staging candidate pending remaining release gates.

= 0.6.0-rc.4 =
* Bounded historical lot conversion and guarded recovery, Store API child variation intent validation, safe closed-reservation payment retry rejection, indexed legacy referral attribution and fuller privacy exports.
* Bounded pending maturity and expiry, durable paid-confirmation/failed-payment recovery, UTC-aware admin dates, translated dynamic labels and visible active tabs on narrow screens.
* Preserve deferred referral attribution on storage failures; retain catalogue continuations on failed reads; rebuild commission facts across held/deleted histories without removing financial holds.
* Staging candidate: consult release gates before production use.

= 0.6.0-rc.3 =
* Staging candidate: indexed commission history and customer lookup; durable notification outbox; bounded wallet rebuilds and reversal recovery holds.
* Preserve immutable financial beneficiaries after order reassignment.
* Interrupted notification sends require review before retry; accepted is not inbox delivery confirmation.

= 0.6.0-rc.2 =
* Transactional wallet balance read model with read-only counter integrity checks.
* Indexed background catalogue with individual variation discovery and keyset pages.
* Strict imported points-price validation and verified schema migration failures/retries.
* Local candidate only: broad compatibility, notification durability and historical commission scale work remain open.

= 0.6.0-rc.1 =
* Free: audit history, saved-rule differences, read-only wallet checks and redacted support reports.
* Free: upcoming point changes, owner-only bounded statements, named customer lookup and referral cursor navigation.
* Pro: campaign selection preflight and shared-role overlap review.
* Schema 10: filtered audit cursor indexes. Historical balances are not recalculated.
* Development candidate: complete the broader compatibility and catalog edge-case validation before general rollout.


= 0.5.2 =
* Add Freemius Free/Pro editions with a skippable Free account connection.
* Keep core rewards, cancellation/refund settlement and privacy tools independent of licensing.
* Separate scheduled campaigns, summary reports and custom email template editing into Pro-only files.
* Preserve existing settings and accounting history across edition changes.
* Include Freemius SDK 2.13.5 (GPL-3.0) for licensing and updates.

= 0.4.8 =
* Make existing rounding controls visible in the essential Purchase rewards settings beside the earning rate.
* Explain floor, nearest/half-up, and ceiling behavior, including order-line quantities and referral commissions.
* Preserve the current rounding choice and historical awards when updating the plugin.

= 0.4.7 =
* Remove product, legacy-order, HPOS, and order-item rewards metadata when full uninstall deletion is explicitly enabled.
* Filter unavailable and invalid hybrid rewards before pagination and prime product caches for the displayed page.
* Improve dropdown keyboard focus, labels, and dashboard text contrast.
* Sanitize notification subjects after substituting customer/site values.
* Clear consumed classic zero-total reward carts before the redirect, and return structured Store API conflicts for competing checkouts.
* Protect paid/confirmed orders from a failing duplicate checkout request.

= 0.4.6 =
* Cancelled orders release reserved points, restore unreturned spent points, and reverse remaining purchase/referral earnings atomically.
* Prevent duplicate restoration after partial refunds and repeated cancellation callbacks.
* Add an administrator-only cancellation accounting retry action and a Cancelled redemption filter.
* Cash refunds remain a separate WooCommerce payment operation. Cancelled orders are final for rewards; use a new order for a new purchase.

= 0.4.5 =
* Harden catalog filtering and honor the configured global redemption default.
* Clean up parameterized notification and reservation jobs on deactivation.
* Restrict uninstall notification-option matching to the exact plugin prefix.
* Add directory-format documentation and consistent compatibility metadata.

== Upgrade Notice ==

= 0.4.8 =
Rounding is now visible beside the earning rate. Choose Round to nearest for 8.99 to earn 9 points at a 1:1 rate. Save to apply to future awards.

= 0.4.7 =
Improves uninstall cleanup, catalog pagination, checkout retries, and accessibility. Back up the site before updating.

= 0.4.6 =
Cancellation restores/reverses points. Refund paid cash through WooCommerce. Historical cancelled orders require individual review.

= 0.4.5 =
Back up files and the database before updating. Review the global redemption default and verify the customer reward catalog after installation.
