# Multi Payment Gateway Plugin for aMember Pro

A payment integration plugin for aMember Pro that enables multiple payment gateways through a single interface.

## Overview

This plugin allows aMember users to integrate Multi Payment Gateway as a payment method, providing access to multiple payment processors through a single implementation.

## Requirements

- aMember Pro 6.0 or higher (https://amember.com)
- PHP 7.4 or higher
- Active Multi Payment Gateway account (https://payment-gateway.app)

## Installation

1. Download the plugin file
2. Copy the `multi-payment-gateway.php` directory to:
   ```
   amember/application/default/plugins/payment/
   ```
3. Log in to your aMember Pro admin panel
4. Navigate to `Configuration -> Plugins`
5. Locate "Multi Payment Gateway" and click "Enable"

## Configuration

1. Go to `Configuration -> Setup/Configuration`
2. Select "Multi Payment Gateway" from the plugins list
3. Configure the following settings:
   - Default Main Backend Domain
   - Site Secret
4. Click "Save" to apply changes

## Changelog

### 1.0.0

- Initial release

# Multi Payment Gateway Plugin for aMember Pro

This plugin integrates the Multi Payment Gateway with aMember Pro, allowing you to accept payments through a variety of processors via a single, secure interface.

## Overview

By adding this plugin to your aMember Pro installation, you can offer a seamless and secure payment experience to your customers. The integration is designed to be robust, handling all payment status updates through a secure webhook (IPN) system.

## Requirements

- aMember Pro v6.0 or higher
- PHP v7.4 or higher
- An active Multi Payment Gateway account

## Installation

1.  **Download the Plugin**: Obtain the `multi-payment-gateway.php` file.
2.  **Upload to Server**: Copy the `multi-payment-gateway.php` file to the following directory in your aMember installation:
    ```
    /amember/application/default/plugins/payment/
    ```
3.  **Enable the Plugin**:
    - Log in to your aMember Pro admin panel.
    - Navigate to `Configuration` -> `Plugins`.
    - Find "Multi Payment Gateway" in the list and click `Enable`.

## Configuration

1.  Navigate to `Configuration` -> `Setup/Configuration`.
2.  Click on the `Multi Payment Gateway` plugin to open its settings.
3.  Configure the following fields:
    - **Default Main Backend Domain**: Enter the domain name of your Multi Payment Gateway main backend (e.g., `api.example.com`). Do not include `https://`.
    - **Site ID**: Paste the unique Site ID from your Multi Payment Gateway site's edit page.
    - **Site Secret Key**: Paste the unique Site Secret Key provided by your Multi Payment Gateway site configuration.
    - **Pass Billing Address**: (Optional) Check this box to send the customer's billing address to the payment gateway. This is recommended for fraud prevention and address verification.
    - **Pass Items**: (Optional) Check this box to send a detailed list of items in the invoice to the payment gateway.
4.  Click `Save` to apply the changes.

## Webhook / IPN

This plugin uses a secure webhook (IPN) to receive real-time status updates from the Multi Payment Gateway. The webhook URL is automatically configured and can be found in your aMember Pro payment plugin settings.

- **URL**: `[Your aMember URL]/payment/multi-payment-gateway/ipn`
- **Security**: All incoming IPN messages are verified using a timestamp and an HMAC-SHA256 signature to ensure they are authentic and have not been tampered with.

## Changelog

### v1.1.1

- Feature: Added `Site ID` to plugin configuration for authenticating with the new API endpoint.
- Docs: Updated README with instructions for the new `Site ID` field.

### v1.1.0

- Updated IPN handler to use a secure, timestamp-based HMAC-SHA256 signature validation.
- Aligned status handling with the backend's integer-based codes.
- Improved logging for webhook validation failures.

### v1.0.0

- Initial release.
