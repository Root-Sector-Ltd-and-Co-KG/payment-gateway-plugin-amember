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
