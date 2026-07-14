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

## Troubleshooting logs

aMember may log payment-session and IPN failures when error reporting is
enabled. The plugin logs safe gateway metadata such as gateway code, request
ID, transaction ID, external reference, dispute status, and customer-risk-hold
fields instead of full checkout request bodies. Avoid verbose logging on
production unless you are diagnosing an issue, and restrict access to log files
to trusted administrators.

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

## Changelog

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
