# Verify a release

Use the version, source revision, package hashes and verification notes published together on the [GitHub releases page](https://github.com/abdulhameed-developer/rewards-referrals/releases). A repository commit, an uploaded Freemius deployment, a WordPress.org submission and a publicly released update are different states.

## Package identity

Compare the downloaded Free ZIP's SHA-256 with the release's published checksum. The archive should contain one `rewards-referrals/` plugin folder and `rewards-referrals.php` as its main entry. Its plugin header/version and readme stable tag should agree. Keep the downloaded package with your backup so recovery uses the exact version you installed.

The Free distribution excludes private premium implementation files and development/test fixtures. The bundled SDK's original license texts and THIRD-PARTY-NOTICES are retained. The two form-construction adapters are independently authored compatible replacements; the notice identifies them. Source and build references for the unchanged compiled pricing application appear in readme.txt.

## Verification scope

Read the release notes for the source/binary actually tested, test outcomes, known limitations and support requirements. Passing accounting tests does not establish every browser, theme, gateway or language combination. Internal review is not an external penetration test or a universal security/accessibility certification.

Before updating a store, perform the staged procedure in [DEPLOYMENT-ROLLBACK.md](DEPLOYMENT-ROLLBACK.md). Confirm saved policies and representative balances/history; test one applicable purchase/redemption/refund/cancellation journey for your configuration.

Do not install a prerelease as though it carried a stable-release declaration. If a candidate remains Unreleased in Freemius, that is not a customer rollout. A WordPress.org review submission is not approval or SVN publication. Use the actual release channel and status recorded by the maintainer.
