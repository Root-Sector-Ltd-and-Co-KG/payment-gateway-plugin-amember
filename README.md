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
1. Download the plugin file: `payment-gateway.php`
2. Upload it to `/amember/application/default/plugins/payment/`
3. Log in to the aMember Pro admin panel.  
4. Go to **Configuration → Plugins** and click **Enable** next to "Payment Gateway App".

## Configuration
1. In the admin panel open **Configuration → Setup/Configuration**.  
2. Select the **Payment Gateway App** tab.  
3. Fill in the fields:  
• **API Domain** – domain of your Payment Gateway backend  
  (e.g. `api.payment-gateway.app`, **without** `https://`).  
• **Site ID** – value shown on your Site’s page in the Payment Gateway App.  
• **Site Secret Key** – copy the secret key from the same page.  
• Optional: *Pass Billing Address* / *Pass Items* for extra fraud checks.  
4. Click **Save**.

## Webhook (IPN)
The plugin registers a secure IPN endpoint automatically:
`[YOUR-AMEMBER-URL]/payment/payment-gateway-app/ipn`

Incoming messages are verified with an HMAC-SHA256 signature and
timestamp to prevent tampering.

## Changelog

### 1.0.1

- Updated IPN handler to use a secure, timestamp-based HMAC-SHA256 signature validation.
- Aligned status handling with the backend's integer-based codes.
- Improved logging for webhook validation failures.
- Feature: Added `Site ID` to plugin configuration for authenticating with the new API endpoint.
- Docs: Updated README with instructions for the new `Site ID` field.

### 1.0.0

- Initial release.
