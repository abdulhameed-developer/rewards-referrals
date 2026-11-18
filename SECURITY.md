# Security reports

Report suspected vulnerabilities privately to **abdulhameedsaleem555@gmail.com**. Include the affected plugin version, WordPress/WooCommerce/PHP versions, required user role, minimal reproduction steps and the observed effect. Use disposable data and remove secrets/customer details from logs and screenshots.

Do not publish exploit details or real customer information in a public issue while the report is being investigated. No guaranteed response period, bounty or universal security certification is implied.

Reports should distinguish a reproducible vulnerability from a scanner warning. A prepared-query or direct-storage warning can require contextual review; it is not automatically harmless or exploitable.

For operational controls, privacy and tested-scope guidance, see [SECURITY-PERFORMANCE.md](docs/SECURITY-PERFORMANCE.md).

## Verified maintenance in 0.6.4

A first-party WordPress HTTP boundary now enforces verified HTTPS, certificate/hostname validation and the canonical WordPress CA bundle for the official Freemius service origins. It rejects plaintext API fallback, unsafe authorities and unsafe redirect destinations, retains bounded verified HTTPS CDN downloads and strips origin credential headers across allowed download redirects. Bundled SDK source is unchanged. No actual interception or credential exposure is claimed; signup requests already requested TLS verification, while the separately verified shared API path could request false verification.

The commercial edition also rejects blank, whitespace-only or empty-after-sanitization event email subject/body overrides at the authorized server-side save boundary. Character-safe length limits preserve valid Unicode. Only that existing Pro save method changed; its implementation remains excluded from this Free tree.

Use the supported 0.6.4 package or a later verified maintenance release. Do not work around a trust error by disabling TLS checks or enabling plaintext fallback. Check the server CA/certificate configuration and contact support through the private reporting address above. Public prior release archives remain immutable historical artifacts; their presence is not a recommendation to keep the older transport behavior.

## Verified maintenance in 0.6.5

A native database rollback swallowed by a third-party order save could previously let a later recovery checkpoint appear complete. The maintenance patch checks transaction liveness and blocks unsafe subsequent SQL, retaining durable recovery/native retries. This is an observed synthetic accounting-integrity defect, not a claim of customer credential exposure or a recorded merchant incident. Existing authorization, ownership and HTTPS controls remain in place.

Use a verified maintenance package and matched backups. Do not clear a recovery hold by editing the database. Review older potentially affected orders with supported retry/recovery actions: this patch does not silently rewrite order history. Native deadlock and lock-timeout recovery were exercised; automatic reconnect/network/process interruptions retain the separate documented operational limits.
