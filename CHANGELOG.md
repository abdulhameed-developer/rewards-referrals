# Changelog

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
