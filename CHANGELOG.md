# Changelog

## 0.6.5 — interrupted accounting recovery and localization maintenance

- Detect loss of the live wallet transaction and block subsequent unsafe SQL after native database deadlocks/lock timeouts.
- Preserve cancellation/refund/confirmation/release recovery and native award retries; do not record false completion after rollback.
- Preserve immutable nested/caller-owned rollback boundaries and existing financial records. This patch does not scan or silently rewrite historical orders; inspect affected orders through supported recovery actions.
- Correct localized earning-rate decimals, primary point-price plurals and POT extraction of short labels.
- Preserve all Free/Pro capabilities, schema 23, installed identity, SDK/assets, license notices and existing HTTPS protections.
- Targeted 1/2/4/8-worker, real deadlock/timeout and native lifecycle tests passed within their documented scopes. Full-package consolidated acceptance is PASS.


## 0.6.4 — verified Freemius transport security maintenance

- Enforce certificate and hostname verification at the WordPress HTTP boundary, including when another SDK instance previously set a shared verification constant false.
- Block plaintext API fallback and unsafe redirect destinations. Preserve bounded verified HTTPS CDN downloads, removing origin authorization/cookies before allowed cross-origin download requests.
- Preserve same-origin verified HTTPS 307 request/body semantics and the existing Free/Pro identities, financial logic, database schema 23, SDK/assets, dependencies and UI.
- Pro: validate nonempty event email overrides on the server and apply character-safe Unicode length limits. This is a correction to an existing authorized save workflow; no Pro implementation is shipped in Free.
- This fixes a verified transport-policy weakness; no actual interception or customer credential exposure is asserted. The Free release adds one first-party module and reviewed early bootstrap initialization, plus version/translation/release metadata.


## 0.6.3

- Allow an ordinary cash product with an unused zero points price to save in the native product editor.
- Preserve positive-price reward eligibility, financial policies, all 0.6.2 corrections, technical identifiers, SDK/assets and schema 23.

This is one input-constraint correction and four metadata files. Matching-candidate policy checks, exact package checks and native final-ZIP acceptance passed. The isolated 0.6.3 update preserved all ten tracked financial table hashes, settings and rule version; all 294 installed files match the installer. A subsequent native zero-price cash-product Update succeeded. Prior 0.6.2 evidence remains separate and unchanged; no new full licensed Pro, gateway or universal compatibility suite is claimed.

## 0.6.2

- Preserve catalog search, filters and page after applying selected product reward changes.
- Keep reusable rewards component IDs and control references distinct while preserving full-page pagination and canonical account links.
- Prepare ledger CSV before sending attachment headers and report database/storage errors without returning a misleading partial export.
- Preserve earning, referral, redemption, data-retention policies, SDK/assets and schema 23.

This maintenance change has focused source/runtime checks for the three existing-feature corrections and exact installer syntax/archive checks. Counters and limits are separated in RELEASE-MANIFEST.json; earlier 0.6.1/0.6.0 results remain historical evidence for the corresponding exact bytes and unchanged modules. The exact 0.6.2 Free package passed native update, runtime identity and data-preservation acceptance on protected test sites; the separate scopes are recorded in the manifest. No new capability, universal certification or fresh licensed Pro/gateway/email test is claimed.

## 0.6.1

- Improve contrast for customer explanations, referral labels, reward availability, empty states, table headings and pagination.
- Preserve layout, earning and redemption policies, accounting logic, all existing Free/Pro capabilities, schema 23 and installed identifiers.
- Keep the 0.6.0 release and its installer unchanged. Correct the current Plugin URI extraction in the translation template.

This maintenance change passed an independent review of five source files, focused rendered contrast, temporary German/Arabic translation-layout and keyboard checks, and 8 native update checks on the protected clone using the exact Free ZIP. All 18 tracked table row hashes, saved settings and active plugins were preserved. The 0.6.0 core regression result remains explicitly historical evidence for unchanged business logic; no new full 0.6.1 regression count or complete accessibility/translation certification is claimed. See RELEASE-MANIFEST.json and the verification guide for the exact installer and final package-acceptance status.

## 0.6.0

- Preserve the existing earning, direct referrals, selected-product redemption, customer wallets and administrative workflows.
- Bind retained rewards history to account ownership so a reused numeric user ID cannot claim another account's rewards or history.
- Preserve unattributable historical records and protect financial writes while an account requires review.
- Keep commission, referral, migration, reconstruction and reservation maintenance progressing past protected accounts without unlocking their points.
- Preserve site-specific ownership during multisite cleanup; network activation remains unsupported.
- Replace two upstream form adapters with independently authored compatible adapters and retain corresponding-source references and third-party notices.
- Use stable 0.6.0 metadata and the distinctive directory display name while retaining the installed plugin basename, public APIs and data identifiers.

The attached installation archive passed 61 consolidated runs with 1,493 explicit RESULT assertions and no failures. These counters do not include repeated historical/focused runs or generated property iterations. See RELEASE-MANIFEST.json and the [verification guide](docs/RELEASE-VERIFICATION.md) for scope and package identity. This is not universal gateway, theme, security or accessibility certification.
