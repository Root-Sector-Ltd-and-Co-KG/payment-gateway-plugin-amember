# Payment Gateway App Plugin for aMember Pro

A payment-integration plugin for aMember Pro that connects your site to your
self-hosted Payment Gateway App instance, giving you access to multiple
payment processors through a single interface.

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
| **API Key**                | Create one under Payment Gateway App admin → API Keys with `checkout:write` scope.                   |
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

**Replay protection:** Requests with a timestamp older than 5 minutes
are automatically rejected.

If you suspect your Webhook Signing Secret has been compromised,
regenerate it in Payment Gateway App admin → Sites → Edit and update
the value in the aMember plugin settings.

## Changelog

### 1.0.2

- Enhancement: Display and log customer-risk-hold checkout blocks and safe bank-transfer restrictions with request IDs.
- Security: Replaced Site Secret Key with dedicated Webhook Signing Secret (`whsec_` prefix) for IPN verification.
- Security: Added separate API Key field for checkout session authentication.
- Enhancement: Improved webhook verification with HMAC-SHA256 + timestamp replay protection.
- Docs: Updated README with Webhook/IPN details and new configuration fields.

### 1.0.1

- Updated IPN handler to use a secure, timestamp-based HMAC-SHA256 signature validation.
- Aligned status handling with the backend's integer-based codes.
- Improved logging for webhook validation failures.
- Feature: Added `Site ID` to plugin configuration for authenticating with the new API endpoint.
- Docs: Updated README with instructions for the new `Site ID` field.

### 1.0.0

- Initial release.
