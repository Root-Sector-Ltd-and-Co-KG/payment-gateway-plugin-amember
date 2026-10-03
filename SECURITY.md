# Security policy

## Report a vulnerability

Report suspected vulnerabilities privately through this repository's
[GitHub Security Advisory form](https://github.com/Root-Sector-Ltd-and-Co-KG/payment-gateway-plugin-amember/security/advisories/new).
Do not open a public issue for an undisclosed vulnerability.

Include the affected plugin and aMember versions, the security impact, and the smallest safe
reproduction you can provide. Remove API keys, webhook signing secrets, signed request bodies,
customer information, database contents, licensed aMember files, and production logs before
submitting the report.

## Operational security

- Serve the aMember site and IPN endpoint over trusted HTTPS.
- Give the plugin a dedicated API key with only the required gateway scope and use the site's
  dedicated `whsec_` webhook signing secret.
- Rotate a credential immediately if it may have been exposed, then update the aMember plugin and
  gateway site configuration together.
- Restrict database, application, backup, and log access to trusted administrators and the runtime
  account. Debug logging is off by default and should be enabled only for bounded diagnosis.
- Do not delete, truncate, or hand-edit durable IPN v2 receiver state to force a replay. Corrupt
  state fails closed before effects; recover it from a trusted backup and reconcile the invoice and
  gateway delivery before retrying.
