# Security, privacy and performance scope

The plugin maintains a rewards ledger, point lots, reservations, refund reversals and audit records. Administrative writes require WordPress authorization; customer-facing history and statements are scoped to the signed-in account. Accounting mutations use transaction/locking and replay protections. These controls require healthy transactional storage and do not establish that every host, extension or future software version is certified.

## Operational requirements

Use HTTPS for customer accounts and protect WordPress administrator access. Keep WordPress, WooCommerce, PHP and the plugin maintained. Use restrictive host file permissions; do not expose development installations publicly. Take complete, recoverable backups before updates and account imports.

Review reward policies before enabling redemption. Changes to policy settings apply prospectively. Do not directly edit ledger rows or ownership records to correct balances; use documented staff adjustments and retain a reason. Investigate unavailable-wallet or recovery messages rather than retrying payments against a closed reward reservation.

## Customer data and external services

Rewards accounting is stored in the site's database. Staff search can contain derived account names and email addresses. WordPress privacy tools export supported rewards records; erasure reports retained accounting rather than silently deleting balances and audit history. The store owner must establish its own retention basis and customer notices.

Referral attribution can set the first-party HTTP-only, SameSite=Lax `br_ref` cookie for the configured period. Store owners are responsible for required consent and can use the documented `rr_referral_tracking_allowed` filter to integrate that policy.

Free can operate without connecting a Freemius account. Connection or Pro license activation shares the information shown by the SDK consent screen with Freemius. See the [Freemius privacy policy](https://freemius.com/privacy/) and [terms](https://freemius.com/terms/). Optional reward emails use the site's WordPress mail service. A successful transport acceptance is not proof that a message reached an inbox.

## Measured performance versus compatibility claims

Queries and customer/catalog pages use bounded batches and indexes. Test reports distinguish helper measurements, synthetic fixtures and real browser/order workflows. A warm search benchmark does not establish production checkout throughput, and a syntax check is not full application compatibility.

Consult the release's verification notes for exact tested versions, HPOS/legacy/cache conditions, unresolved test limitations and external integrations. Stripe/PayPal callbacks, licensed WoodMart workflows and controlled external mail delivery require their own authorized environments. Existing integration code is preserved; certification must be based on observed tests.

Report a suspected vulnerability privately using [SECURITY.md](../SECURITY.md). Do not include real customer records, credentials or payment details in a public issue.

## Verified Freemius transport boundary in 0.6.4

A first-party WordPress HTTP boundary now enforces verified HTTPS, certificate/hostname validation and the canonical WordPress CA bundle for the official Freemius service origins. It rejects plaintext API fallback, unsafe authorities and unsafe redirect destinations, retains bounded verified HTTPS CDN downloads and strips origin credential headers across allowed download redirects. Bundled SDK source is unchanged. No actual interception or credential exposure is claimed; signup requests already requested TLS verification, while the separately verified shared API path could request false verification.

The enforcement is loaded before this product starts the SDK and also works when shared SDK verification constants already exist. It uses the native WordPress/Requests request and redirect boundaries. It does not rewrite the SDK, introduce another HTTP client, trust an arbitrary public certificate or force production to one transport. It cannot secure HTTP actions that other trusted PHP code executed before it loaded, or defend against malicious server code altering callbacks. The relevant WordPress CA file must remain readable and current.
