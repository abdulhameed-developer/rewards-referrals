# Contributing to the public Free edition

This repository contains the public Free edition of Rewards & Referrals by Abdul Hameed. Keep commercial implementation, private engineering history, customer exports, credentials and disposable account fixtures outside it. Preserve the installed plugin basename, data/API identifiers and release history unless a tested migration is required.

## Branches and pull requests

Keep `main` as stable Free source. Use a lowercase, descriptive working branch:

- `fix/<specific-issue>` for a confirmed bug.
- `hotfix/<critical-issue>` for urgent remediation.
- `maintenance/<specific-task>` for maintenance.
- `test/<scenario>` for test tooling.
- `docs/<topic>` for documentation.
- `release/<version>` for controlled release preparation.
- `security/<issue>` for coordinated security work.

Do not publish agent/tool identity prefixes such as `codex/`, or vague names such as `final-final`. Open a focused PR against `main`; explain the problem, resulting behavior, actual verification and remaining limits. Sensitive reports belong at the private address in [SECURITY.md](SECURITY.md), without public exploit details or customer data.

Before deleting a merged branch, verify its tip is reachable from main, intended changes are preserved, no open PR depends on it, and it is unprotected. Retain unmerged or uncertain work. Preserve PR history and release tags. Maintainer-enabled automatic merged-head deletion does not authorize deleting main or release tags.

## Required check and its limits

The stable job/check name is **Free source validation**, in the **Public Free checks** workflow. It runs on every PR change to main, including documentation, and on approved working-branch prefixes. It validates:

- Public Free/bootstrap identity, version consistency and declared frozen source hashes.
- Private/Pro paths and selected strong credential indicators, without printing matched values.
- First-party PHP and browser JavaScript syntax, without bootstrapping WordPress or executing plugin code.

The hosted job uses `ubuntu-24.04`, verifies its PHP8.3 parser and records actual PHP/Node versions. Node20 or newer is required. It does not install dependencies, use account secrets, send mail, contact payment providers, run plugin callbacks, publish or deploy. Its only token permission is `contents: read`; checkout does not persist credentials. The sole external action is pinned to a reviewed full commit SHA.

Run locally from the repository root with PHP and Node installed:

```sh
RR_BRANCH_NAME=maintenance/release-governance node .github/scripts/validate-free-source.mjs
```

Local parsing uses the installed PHP; the hosted workflow explicitly checks PHP8.3. Static/syntax PASS does not establish accounting correctness, complete secret detection, multi-version compatibility, provider/inbox acceptance, accessibility certification or directory approval. Bundled vendor code is preserved and reviewed separately, outside the first-party syntax count. Match additional tests to actual product changes and record exact source/package identity.

Only require this check in main protection after its actual GitHub run exists and passes. Do not configure nonexistent check names or approval counts that permanently block the sole maintainer. Protect main against force pushes/deletion and preserve immutable release tags where supported. Settings and any emergency bypass remain maintainer controls; this document does not configure them.

## Release integrity

Documentation/CI governance does not alter the installed0.6.5 plugin or justify rebuilding published archives. Preserve v0.6.0–v0.6.5 tags and checksums. Never publish different installer bytes under one version. A confirmed product correction needs a suitable maintenance version, consistent metadata, targeted regression evidence and a separately verified package.

Never push a public recovery mirror or merge private commercial history here. Inspect changed files/history before publication for Premium implementation, secrets, private fixtures and customer data. Pattern checks are a safeguard, not authorization to publish unknown material.

## Workflow maintenance references

- [GitHub secure workflow guidance](https://docs.github.com/en/actions/reference/security/secure-use)
- [Permissions and workflow syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax)
- [Pull-request event behavior](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#pull_request)
- [Pinned official checkout v7.0.1](https://github.com/actions/checkout/tree/3d3c42e5aac5ba805825da76410c181273ba90b1)
- [Ubuntu24.04 image inventory](https://github.com/actions/runner-images/blob/main/images/ubuntu/Ubuntu2404-Readme.md)

Review upstream security updates before changing a pin. Hosted images evolve; actual logs identify the image/tool versions used. Do not replace this PR trigger with `pull_request_target` or a privileged follow-up that runs untrusted PR code.
