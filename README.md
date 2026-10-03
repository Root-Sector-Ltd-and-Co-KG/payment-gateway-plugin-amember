# Payment Gateway App Plugin for aMember Pro

A payment-integration plugin for aMember Pro that connects your site to your
self-hosted Payment Gateway App instance, giving you access to multiple
payment processors through a single interface.

**Version:** dev

## Overview

With this plugin you can offer a seamless checkout experience in aMember
while keeping all gateway configuration and webhook handling inside
Payment Gateway App.

## Requirements

- aMember Pro 6.3 or higher (https://amember.com)
- PHP 8.2 or higher
- An Active Payment Gateway account (https://payment-gateway.app)

## Installation

1. Download the plugin file: `payment-gateway-app.php`
2. Upload it to `/amember/application/default/plugins/payment/payment-gateway-app/`
3. Log in to the aMember Pro admin panel.
4. Go to **Configuration → Plugins** and click **Enable** next to "Payment Gateway App".

## Configuration

1. In the admin panel open **Configuration → Setup/Configuration**.
2. Select the **Payment Gateway App** tab.
3. Fill in the fields:

| Field                      | Description                                                                                          |
| -------------------------- | ---------------------------------------------------------------------------------------------------- |
| **API Domain**             | Domain of your Payment Gateway backend (e.g. `api.payment-gateway.app`, **without** `https://`).     |
| **Site ID**                | Value shown in Payment Gateway App admin → Sites → Edit.                                             |
| **API Key**                | Create one under Payment Gateway App admin → API Keys with `checkout:create` scope.                  |
| **Webhook Signing Secret** | The `whsec_`-prefixed secret from Payment Gateway App admin → Sites → Edit → Webhook Signing Secret. |
| _Pass Billing Address_     | Optional – sends customer billing data for fraud checks.                                             |
| _Pass Items_               | Optional – sends line-item details to the checkout session.                                          |

4. Click **Save**.

## Webhook (IPN)

The plugin registers a secure IPN endpoint automatically:

```
[YOUR-AMEMBER-URL]/payment/payment-gateway-app/ipn
```

When a transaction status changes, Payment Gateway App sends a POST
request to this URL. Each request includes two signature headers:

| Header                    | Purpose                                                                          |
| ------------------------- | -------------------------------------------------------------------------------- |
| `X-Signature-Timestamp`   | Unix timestamp (seconds) of when the request was signed                          |
| `X-Signature-HMAC-SHA256` | HMAC-SHA256 hex digest of `{timestamp}.{body}` using your Webhook Signing Secret |

The plugin verifies both headers before processing. Requests with
invalid or missing signatures are rejected and logged.

Version 1.2.0 adds the IPN v2 receiver used by durable gateway delivery. IPN
v2 requests include `X-IPN-Version: 2`, `X-IPN-Delivery-ID`, and a signed
envelope with stable delivery and event identities. Install this plugin before
selecting v2 for the site. Existing or unversioned sites remain v1; newly
created sites default to v2. The gateway selects one explicit version per
site, with no automatic negotiation or dual-send.

Legacy v1 remains supported through **2027-06-30**. This date does not trigger
automatic shutdown. Keep the old destination URL and signing configuration
available for existing deliveries and manual retries until their migration is
complete. Do not downgrade to a plugin that predates v2 support.

Once a transaction or signed checkout session has accepted v2, subsequent v1
notifications for that identity are acknowledged without effects. Switching a
site back to v1 therefore applies to fresh checkouts, not an existing v2
transaction. Use v2 for subsequent refunds or replay on that transaction.
Distinct new checkout identities can still use v1.

The receiver retains transaction watermarks, the latest effect receipt and
accepted session identities for the invoice lifetime. Delivery history is
bounded to 100 entries per invoice and compacted after 48 hours plus one hour
of safety. History cleanup does not erase ordering or partial-effect recovery.
Manual retries use a fresh signature over the original immutable notification.
Never clear receiver state to force a replay.

### Upgrading receiver state

Back up the aMember database before installing an updated plugin. This release stores durable IPN
v2 receiver state in aMember's native blob field so delivery history, transaction watermarks,
effect receipts, and accepted checkout sessions are not limited by the scalar field's 255-byte
capacity. A valid state written by the earlier scalar implementation remains readable and moves to
blob storage on its next successful receiver update.

Malformed or truncated existing state fails closed before payment, access, refund, void, or
chargeback effects. Do not delete or edit the state record and do not downgrade the plugin after an
invoice has accepted IPN v2. Restore the affected invoice data from a trusted database backup or
contact your Payment Gateway App operator to reconcile the gateway delivery and aMember records.
After reconciliation, retry the original immutable notification through the gateway so it receives
a fresh timestamp and signature. Confirm the invoice payment/refund records and membership access
before retrying again.

The signed `ipn.test` readiness control message is **not yet supported by the
aMember endpoint**. Payment v1/v2 receiver tests do not qualify the native
aMember request lifecycle. Readiness must not be reported as successful until
a safe pre-invoice framework hook is verified; a generic `OK` is not proof.

### Maintenance and v1 removal (PG-242)

Payments-platform owns the temporary v1 adapter. PG-242 removes it only after
legacy sites, queued deliveries and manual-replay obligations are migrated;
the support date alone is not a removal gate. Delete `processLegacyV1`, v1
branches in `validateIpnVersion`, legacy identity/dispute parsing and the v1
compatibility cases in `tests/ipn-v2.test.php` and customer-risk tests. Keep
`PaymentGatewayAppInvoiceSynchronization`, checkout identity checks, v2
watermarks/effect receipts and all v2 recovery/ordering tests. Keep persisted
protection for existing invoices across plugin upgrades.

A later supported wire version gets an explicit validated dispatch branch and
a narrow adapter; unknown versions fail closed. Reuse invoice synchronization
and effect protection, and define any ordering transition explicitly. Do not
wrap v1 in a synthetic v2 envelope or introduce a version registry.

## Troubleshooting logs

The optional **Enable Debug Logging** setting is off by default. When enabled,
the plugin logs only bounded, allowlisted gateway metadata such as gateway code,
request ID, transaction ID, external reference, dispute status, and
customer-risk-hold fields. It never logs response bodies, backend messages,
credentials, billing data, or customer email addresses. Enable it only while
diagnosing an issue and restrict access to log files to trusted administrators.

Checkout requests blocked by an unresolved dispute use
`CHECKOUT_BLOCKED_BY_DISPUTE` and show a customer-safe support message with the
gateway request ID when available. Final merchant-loss customer risk holds use
`CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD`; when safe methods are allowed,
`CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD` asks the customer to choose an available
bank-transfer option such as wire or Wise.

Gateway API errors include the structured gateway code and request ID when
available. Customers see a safe support message plus the request ID. Admin
logs include the gateway code, request ID, transaction ID, external reference,
amount, currency, dispute date, dispute ID/status, and credit-note references
when the gateway provides them.

Dispute IPN payloads may include `disputeStatus`, `chargebackStatus`, nested
`chargeback.*` fields, or a top-level string `status` such as `open`,
`under_review`, `won`, `lost`, or `accepted`. Numeric transaction statuses are
still handled as normal payment status updates when no dispute status is
present.

For active or merchant-loss dispute statuses (`open`, `under_review`, `lost`,
`accepted`), the plugin records a chargeback idempotently so repeated IPNs do
not create duplicate chargebacks. `won` disputes are intentionally manual-only:
they are logged with request/dispute metadata and return `OK`, but the plugin
does not automatically clear, reverse, or reopen the aMember invoice.

**Replay protection:** Requests with a timestamp older than 5 minutes
are automatically rejected.

If you suspect your Webhook Signing Secret has been compromised,
regenerate it in Payment Gateway App admin -> Sites -> Edit and update
the value in the aMember plugin settings.

Keep the plugin and aMember installation current, serve the IPN endpoint only over trusted HTTPS,
restrict database and aMember data-directory access to the application account, and never include
API keys, webhook secrets, raw signed bodies, customer records, or licensed aMember files in support
messages. See [SECURITY.md](SECURITY.md) for private vulnerability reporting guidance.

## Changelog

### Unreleased

- Fix: Persist durable IPN v2 receiver state through aMember's native blob storage while retaining
  valid legacy scalar state and failing closed on truncated or corrupt records.
- Documentation: Add upgrade, recovery, data-integrity, and private security-reporting guidance.

### 1.2.0

- Enhancement: Add canonical signed IPN v2 envelope validation and durable duplicate/out-of-order delivery handling.
- Reliability: Recover payment, void, refund, and chargeback effects without duplicating aMember receipts after retries.
- Migration: Retain IPN v1 compatibility during the published migration window; install this release before enabling IPN v2.
- CI: Execute the complete IPN v2 receiver regression before packaging a release.

### 1.1.1

- Fix: Keep the release archive filename, packaged PHP revision, and packaged README version synchronized with the release tag.
- CI: Reject non-`x.y.z` release tags and releases without a matching changelog entry.
- Docs: Populate the GitHub release description from the matching changelog section.

### 1.1.0

- Added structured, customer-safe checkout error handling for dispute blocks and customer-risk holds.
- Hardened gateway error logging by sanitizing identifiers and excluding credentials, billing data, and raw backend messages.
- Improved dispute IPN handling with idempotent chargeback recording and manual-only handling for won disputes.

### 1.0.6

- Enhancement: Accept dispute-only IPNs with supported dispute status fields, including nested chargeback status and top-level string `status`.
- Enhancement: Treat `won` disputes as manual-only trace events while recording non-won disputes as idempotent chargebacks.
- Enhancement: Replace full checkout request/response logging with safe structured checkout and gateway error metadata.
- Docs: Clarify request ID, dispute, credit-note, and manual won-dispute behavior.

### 1.0.2

- Enhancement: Display and log customer-risk-hold checkout blocks and safe bank-transfer restrictions with request IDs.
- Security: Replaced Site Secret Key with dedicated Webhook Signing Secret (`whsec_` prefix) for IPN verification.
- Security: Added separate API Key field for checkout session authentication.
- Enhancement: Improved webhook verification with HMAC-SHA256 + timestamp replay protection.
- Enhancement: Display and log Payment Gateway App API request IDs and structured error codes.
- Enhancement: Log dispute-resolution IPNs, including won/lost/accepted status and credit-note references.
- Docs: Updated README with Webhook/IPN details and new configuration fields.

### 1.0.1

- Updated IPN handler to use a secure, timestamp-based HMAC-SHA256 signature validation.
- Aligned status handling with the backend's integer-based codes.
- Improved logging for webhook validation failures.
- Feature: Added `Site ID` to plugin configuration for authenticating with the new API endpoint.
- Docs: Updated README with instructions for the new `Site ID` field.

### 1.0.0

- Initial release.
