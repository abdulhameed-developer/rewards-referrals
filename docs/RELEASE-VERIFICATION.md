# Verify a release

Use the version, source revision, package hashes and verification notes published together on the [GitHub releases page](https://github.com/abdulhameed-developer/rewards-referrals/releases). A repository commit, an uploaded Freemius deployment, a WordPress.org submission and a publicly released update are different states.

## Package identity

Use the attached installable Free ZIP. GitHub's automatically generated source archives are for source inspection and can use a different folder name. Compare the downloaded Free ZIP's SHA-256 with the release's published checksum. The archive should contain one `rewards-referrals/` plugin folder and `rewards-referrals.php` as its main entry. Its plugin header/version and readme stable tag should agree. Keep the downloaded package with your backup so recovery uses the exact version you installed.

The Free distribution excludes private premium implementation files and development/test fixtures. The bundled SDK's original license texts and THIRD-PARTY-NOTICES are retained. The two form-construction adapters are independently authored compatible replacements; the notice identifies them. Source and build references for the unchanged compiled pricing application appear in readme.txt.

## Verification scope

Version 0.6.2 corrects three existing workflows: catalog filters and page survive selected-product changes; reusable customer components have distinct control and link identifiers while ordinary pagination still resolves across requests; and a ledger export is fully prepared before attachment headers are sent. Database or temporary-storage errors return an error instead of a misleading empty or partial CSV. Seven product files changed: three runtime classes and four release/translation metadata files. The financial policy implementations, SDK, assets, schema 23, public APIs and licensing identity are preserved. The 0.6.0 and 0.6.1 tags and installers stay intact.

The focused checks are separate observations, not feature counts or an aggregate certification:

| Scope | Actual evidence | Limits |
|---|---|---|
| Catalog operation context | 30 focused WordPress rendering and normalization checks passed | Candidate source contracts; final native update acceptance is recorded separately. |
| Reusable components | 33 namespace contracts, 21 independent reference-boundary checks, 6 full-page navigation checks and 4 AJAX/REST composition checks passed | Some behavior overlaps. Ordinary requests and independently rendered preview fragments were tested separately. |
| CSV preparation | 47 checks across 16 registered WordPress export callbacks passed | CLI callbacks with temporary SQL fixtures, including first/later read failure and zero/short disk writes; no native browser download is claimed. |
| CSV resource handling | 19 helper contracts passed on each of PHP 8.1.34, 8.2.12 and 8.3.36 | 57 assertions across three runtimes; PHP 8.4 call semantics were reviewed, without a fresh 8.4 execution. |
| Declared minimum stack | 15 checks passed on WordPress 6.9, WooCommerce 10.8.0, PHP 8.1.34 and MariaDB 10.11 | Native activation, earning, cancellation, replay/reconciliation and the two UI corrections; this run preceded the CSV correction. |
| Prepared installer | 181 PHP files and 6 JavaScript files checked with no syntax errors; ZIP integrity and edition exclusions passed | Exact 294-file Free ZIP; syntax/archive checks do not establish installation or complete compatibility. |

Read RELEASE-MANIFEST.json for the exact installer hash, source freeze revision and scoped outcomes. The exact 0.6.2 Free ZIP passed native WordPress replacement on the protected test site: all 294 installed files match, schema 23 and the sole Free active edition are preserved, and six preservation checks confirm all ten tracked financial hash sets, settings, rules and reconciliation components. The separate 38-check package/runtime attestation covers archive boundaries and actual Free runtime identity. A faithful backup-clone rehearsal also preserved all 18 tracked rowsets, settings and ownership state through the native upgrader and a fresh WordPress bootstrap. These are separate acceptance scopes, not an aggregate feature count or a final all-capabilities PASS claim.

A fresh isolated 0.6.1 Free run also passed 148 accounting/cache checks: 128 assertions across 16 refund/spend/cancellation scenarios, 18 fresh-process wallet observations and two financial nonmutation checks. No object cache and dedicated Redis 7.4 were exercised. These results remain tied to the exact 0.6.1 archive and unchanged accounting modules. They are not a new 0.6.2 full suite or high-concurrency HTTP benchmark. The 0.6.0 61-run/1,493-assertion core result is likewise historical evidence for its exact older archive and unchanged modules. None of these counts is added to the focused maintenance counts.

The 33 genuine licensed Pro checks were originally executed on 0.6.0 and carried through 0.6.1 and 0.6.2 only for the unchanged paid implementation. The shared corrections have their own focused evidence above. No fresh 0.6.1 or 0.6.2 license activation, expired/revoked entitlement fixture, paid report browser workflow or native CSV download is claimed.

Ledger CSV preparation holds at most 500 query rows and one serialized row in PHP memory, with the complete export in a private temporary file. The host needs sufficient temporary storage and request time. Later client, network or host interruption can still prevent complete delivery; unexpected process termination can prevent cleanup. Healthy backslash-escape behavior is preserved, without a new RFC 4180 conformance claim.

Temporary German/Arabic test catalogs were used only on the protected clone to exercise selected translated labels and layouts. They are not shipped complete translations. Selected contrast, viewport and keyboard observations do not establish complete assistive-technology, zoom, device or WCAG conformance.

Read the release notes for the source/binary actually tested, test outcomes, known limitations and support requirements. Passing accounting tests does not establish every browser, theme, gateway or language combination. Internal review is not an external penetration test or a universal security/accessibility certification.

Before updating a store, perform the staged procedure in [DEPLOYMENT-ROLLBACK.md](DEPLOYMENT-ROLLBACK.md). Confirm saved policies and representative balances/history; test one applicable purchase/redemption/refund/cancellation journey for your configuration.

Do not install a prerelease as though it carried a stable-release declaration. If a candidate remains Unreleased in Freemius, that is not a customer rollout. A WordPress.org review submission is not approval or SVN publication. Use the actual release channel and status recorded by the maintainer.
