# Verify a release

Use the version, source revision, package hashes and verification notes published together on the [GitHub releases page](https://github.com/abdulhameed-developer/rewards-referrals/releases). A repository commit, an uploaded Freemius deployment, a WordPress.org submission and a publicly released update are different states.

## Package identity

Use the attached installable Free ZIP. GitHub's automatically generated source archives are for source inspection and can use a different folder name. Compare the downloaded Free ZIP's SHA-256 with the release's published checksum. The archive should contain one `rewards-referrals/` plugin folder and `rewards-referrals.php` as its main entry. Its plugin header/version and readme stable tag should agree. Keep the downloaded package with your backup so recovery uses the exact version you installed.

The Free distribution excludes private premium implementation files and development/test fixtures. The bundled SDK's original license texts and THIRD-PARTY-NOTICES are retained. The two form-construction adapters are independently authored compatible replacements; the notice identifies them. Source and build references for the unchanged compiled pricing application appear in readme.txt.

## Verification scope

Version 0.6.1 is a narrow customer text-contrast maintenance release. Its five changed product files contain nine CSS color substitutions and release/POT metadata; business logic, layout rules, schema 23, public APIs and licensing identity are preserved. The 0.6.0 tag and installer stay intact. Its 61-run/1493-assertion core result remains evidence for that exact older archive and unchanged business logic, rather than a new 0.6.1 full-suite execution. Read RELEASE-MANIFEST.json for the current exact-package acceptance and focused maintenance checks.

Temporary German/Arabic test catalogs were used only on the protected clone to exercise selected translated labels and layouts. They are not shipped complete translations. Selected contrast, viewport and keyboard observations do not establish complete assistive-technology, zoom, device or WCAG conformance.

Read the release notes for the source/binary actually tested, test outcomes, known limitations and support requirements. Passing accounting tests does not establish every browser, theme, gateway or language combination. Internal review is not an external penetration test or a universal security/accessibility certification.

Before updating a store, perform the staged procedure in [DEPLOYMENT-ROLLBACK.md](DEPLOYMENT-ROLLBACK.md). Confirm saved policies and representative balances/history; test one applicable purchase/redemption/refund/cancellation journey for your configuration.

Do not install a prerelease as though it carried a stable-release declaration. If a candidate remains Unreleased in Freemius, that is not a customer rollout. A WordPress.org review submission is not approval or SVN publication. Use the actual release channel and status recorded by the maintainer.
