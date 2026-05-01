# Shubo_TbcPayment -- TBC Bank (Flitt) Payment Module for Magento 2

[![Packagist](https://img.shields.io/badge/packagist-shubo%2Fmodule--tbc--payment-orange.svg)](https://packagist.org/packages/shubo/module-tbc-payment)
[![License: Apache 2.0](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](./LICENSE)
[![Magento](https://img.shields.io/badge/Magento-2.4.x-8a2be2.svg)](https://magento.com)

TBC Bank card payment integration for Magento 2 using the [Flitt](https://flitt.com) Embed Checkout SDK. Customers enter card details directly on your checkout page without being redirected to an external payment page.

> **IMPORTANT DISCLAIMER**: This module has NOT been tested in production with real transactions. It has been developed and tested against sandbox/test environments only. Thorough testing with real payment credentials and real cards is required before going live.

## Table of Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Supported Features](#supported-features)
- [Unsupported Features](#unsupported-features)
- [Payment Flow](#payment-flow)
- [Order Status Flow](#order-status-flow)
- [Split Payments (Marketplace)](#split-payments-marketplace)
- [Admin Actions](#admin-actions)
- [Technical Architecture](#technical-architecture)
- [API Endpoints](#api-endpoints)
- [Logging](#logging)
- [Cron Jobs](#cron-jobs)
- [Internationalization](#internationalization)
- [Troubleshooting](#troubleshooting)
- [License](#license)

## Overview

Shubo_TbcPayment integrates TBC Bank card payments into Magento 2 via the Flitt payment platform (formerly known as Fondy/Cloudipsp). The module uses the **Flitt Embed Checkout** approach: an embedded card form renders directly inside the Magento checkout page. The customer never leaves your site during the payment process.

Key highlights:
- Embedded card form on checkout (no redirect)
- Pre-authorization (hold) and automatic capture modes
- Full and partial refunds via Magento credit memos
- Split payment settlement for marketplace/multi-vendor scenarios
- Server-to-server callback + cron reconciler for reliable order processing
- Apple Pay / Google Pay support (via Flitt wallets)
- Customizable card form theme, layout, and color presets

## Requirements

| Requirement | Version |
|---|---|
| Magento | 2.4.8+ |
| PHP | 8.1+ |
| `magento/framework` | >= 103.0 |
| `magento/module-payment` | >= 100.4 |
| `magento/module-sales` | >= 103.0 |
| `magento/module-checkout` | >= 100.4 |
| `magento/module-quote` | >= 101.2 |
| `cloudipsp/php-sdk-v2` | ^1.0 |

You also need a **Flitt merchant account** from TBC Bank with a Merchant ID and Secret Key (password).

## Installation

### Via Composer (recommended)

```bash
composer require shubo/module-tbc-payment
bin/magento module:enable Shubo_TbcPayment
bin/magento setup:upgrade
bin/magento cache:flush
```

### Manual Installation

1. Copy the module files to `app/code/Shubo/TbcPayment/`.
2. Install the Cloudipsp SDK dependency:
   ```bash
   composer require cloudipsp/php-sdk-v2:^1.0
   ```
3. Enable and install:
   ```bash
   bin/magento module:enable Shubo_TbcPayment
   bin/magento setup:upgrade
   bin/magento cache:flush
   ```

## Configuration

Navigate to **Stores > Configuration > Sales > Payment Methods > TBC Bank (Flitt Embed)**.

### Credentials

| Field | Description |
|---|---|
| **Enabled** | Enable or disable the payment method. |
| **Title** | Display name shown to customers at checkout. Default: `TBC Bank (Card Payment)`. |
| **Merchant ID** | Your Flitt merchant ID, provided by TBC Bank. |
| **Password (Secret Key)** | Your Flitt payment password / secret key. Stored encrypted. |

### Payment Settings

| Field | Description |
|---|---|
| **Payment Action** | `Authorize & Capture` (default) -- charges immediately and creates an invoice. `Authorize Only` -- holds funds, requiring manual capture from admin. |
| **Sandbox Mode** | When enabled, uses the sandbox API URL. |
| **Sandbox API URL** | API base URL for sandbox. Default: `https://pay.flitt.com`. |
| **Production API URL** | API base URL for production. Default: `https://pay.flitt.com`. |
| **Payment Lifetime (seconds)** | How long the payment session stays valid. Default: 3600 (1 hour). Range: 300 to 86400 (5 minutes to 24 hours). |
| **Debug** | When enabled, logs all API requests and responses to the dedicated log file. |
| **Sort Order** | Controls the display order of the payment method at checkout. |

### Embed Appearance

| Field | Description |
|---|---|
| **Checkout Theme** | `Light` or `Dark` theme for the embedded card form. |
| **Checkout Theme Preset** | Color preset: Default, Black, Silver, Vibrant Gold, Euphoric Pink, Heated Steel, Nude Pink, Tropical Gold, Navy Shimmer. |
| **Checkout Layout** | `Default`, `Plain`, or `Wallets Only`. |
| **Advanced Embed Options (JSON)** | Raw JSON to override any Flitt embed option. Example: `{"show_email": true, "logo_url": "https://example.com/logo.png"}`. See [Flitt embed docs](https://docs.flitt.com/api/embedded-custom/). |
| **Enable Apple Pay / Google Pay** | Allow customers to pay with Apple Pay and Google Pay in the embed. |

### Split Payments

| Field | Description |
|---|---|
| **Enable Split Payments** | Enable fund distribution to multiple Flitt merchants. |
| **Auto-Settle After Approval** | Automatically send settlement request when payment is approved. If disabled, use the "Settle Payment" button in admin. |
| **Split Receivers** | Dynamic rows table to configure receivers. Each row has: Merchant ID (Flitt), Amount Type (Percentage or Fixed), Amount, Description. |

Config paths (for programmatic access):
```
payment/shubo_tbc/active
payment/shubo_tbc/merchant_id
payment/shubo_tbc/password
payment/shubo_tbc/sandbox_mode
payment/shubo_tbc/api_url
payment/shubo_tbc/sandbox_api_url
payment/shubo_tbc/payment_action_mode
payment/shubo_tbc/payment_lifetime
payment/shubo_tbc/embed_theme_type
payment/shubo_tbc/embed_theme_preset
payment/shubo_tbc/embed_layout
payment/shubo_tbc/embed_options_json
payment/shubo_tbc/enable_wallets
payment/shubo_tbc/split_payments_enabled
payment/shubo_tbc/split_auto_settle
payment/shubo_tbc/split_receivers
payment/shubo_tbc/debug
payment/shubo_tbc/sort_order
```

## Supported Features

| Feature | Status | Details |
|---|---|---|
| Embedded card form (no redirect) | Supported | Flitt Embed SDK renders inside checkout |
| Authorize & Capture (auto-invoice) | Supported | Payment charged on approval, invoice created automatically |
| Authorize Only (pre-authorization) | Supported | Funds held, manual capture via admin button |
| Manual capture from admin | Supported | "Capture Payment" button on order view |
| Void pre-authorized payment | Supported | "Void Payment" button cancels the order; hold expires on bank side |
| Full refund | Supported | Via Magento credit memo |
| Partial refund | Supported | Via Magento credit memo with partial amount |
| Server-to-server callbacks | Supported | Flitt POSTs to `/shubo_tbc/payment/callback` |
| Frontend confirmation | Supported | JS calls `/shubo_tbc/payment/confirm` after embed success |
| Cron reconciler | Supported | Checks stuck orders every 5 minutes |
| Manual status check from admin | Supported | "Check Flitt Status" button queries API and syncs order |
| Split payments (settlement) | Supported | Post-payment fund distribution to sub-merchants |
| Auto-settle after approval | Supported | Configurable per-store |
| Manual settlement from admin | Supported | "Settle Payment" button on order view |
| Apple Pay / Google Pay | Supported | Via Flitt embed wallets (requires Flitt-side setup) |
| Multi-currency | Supported | Sends quote currency code to Flitt |
| Multi-store / multi-website | Supported | All config fields are website-scoped |
| Payment info in admin | Supported | Shows Payment ID, card, RRN, 3DS status, fees, settlement details |
| Localization (EN/KA) | Supported | Card form and API requests use store locale |
| 3D Secure | Supported | Handled by Flitt embed -- 3DS status shown in admin (ECI values) |
| Signature verification | Supported | SHA1 signature on all API calls and callback validation |
| CSP whitelisting | Supported | `pay.flitt.com` whitelisted for scripts, styles, frames, etc. |
| Sensitive data protection | Supported | Secrets encrypted in config, masked in logs |

## Unsupported Features

| Feature | Status |
|---|---|
| Recurring / subscription payments | Not implemented |
| Saved card / tokenization | Not implemented |
| Installment payments | Not implemented |
| Redirect-based checkout (non-embed) | Not implemented (embed only) |
| Partial capture of pre-authorized amount | Not implemented (full capture only) |
| Admin order creation (phone orders) | Not supported (`can_use_internal` = 0) |
| Country restriction | Not implemented (no country validator pool) |
| Payment page on separate URL | Not applicable (embed approach) |

## Payment Flow

```
Customer selects TBC payment at checkout
            |
            v
JS calls POST /shubo_tbc/payment/params
  -> Backend signs params, requests token from Flitt API
  -> Returns checkout token to frontend
            |
            v
Flitt Embed SDK renders card form in checkout
  (Customer enters card details + 3DS if required)
            |
            v
Customer clicks "Place Order"
  -> JS calls paymentService.submit()
  -> Flitt processes the payment
            |
      +-----+-----+
      |           |
   Success      Error
      |           |
      v           v
JS places      Show error
Magento order  message
      |
      v
JS calls POST /shubo_tbc/payment/confirm
  -> Backend checks Flitt status API
  -> If approved: captures payment, creates invoice
  -> Redirects to success page
            |
            v
(Meanwhile) Flitt sends POST callback to
  /shubo_tbc/payment/callback
  -> Verifies signature
  -> Updates order if not already processed
  -> Triggers settlement if configured
            |
            v
(Every 5 min) Cron reconciler checks stuck orders
  -> Queries Flitt status API
  -> Processes approved / cancels declined or expired
```

**Key design decision**: The Magento order is created *after* Flitt processes the payment (embed success event), not before. This prevents ghost orders from abandoned 3DS flows or invalid card data.

## Order Status Flow

```
Order Placed ──> pending_payment
                     |
          +----------+----------+
          |          |          |
       Approved   Declined   Expired
          |          |          |
          v          v          v
    processing    canceled   canceled
    (invoice)

If Payment Action = "Authorize Only":
    Approved ──> processing (funds held, no invoice)
                     |
              +------+------+
              |             |
           Capture        Void
              |             |
              v             v
         processing     canceled
         (invoice)
```

## Split Payments (Marketplace)

Split payments allow distributing order funds to multiple Flitt merchants after a payment is approved. This is designed for marketplace scenarios where the platform takes a commission and vendors receive their share.

### How It Works

1. The full payment amount is collected by your main Merchant ID.
2. After approval, a **settlement** request distributes funds to configured receivers.
3. The remainder always stays with the main merchant.

### Configuration Methods

**Admin-configured receivers** (static): Set fixed receivers in the admin panel. Every order uses the same split rules.

**Event-based receivers** (dynamic): Other modules (e.g., a Commission module) can listen to the `shubo_tbc_settlement_collect_receivers` event and provide per-order split data. Event-based receivers take priority over admin-configured ones.

### Amount Calculation

Settlement supports mixed modes:
- **Fixed amounts** are deducted first from the order total.
- **Percentage amounts** are then applied to the remaining amount.

Example: Order total = 100 GEL, Receiver A = 5 GEL fixed, Receiver B = 20%
- Receiver A gets 5 GEL
- Receiver B gets 20% of (100 - 5) = 19 GEL
- Main merchant keeps 76 GEL

### Settlement API Format

Settlement uses a different request format than other Flitt APIs: the order data is base64-encoded and signed with `sha1(password|base64_data)` (version 2.0 signature).

## Admin Actions

The following buttons appear on the order view page for TBC-paid orders:

| Button | Appears When | Action |
|---|---|---|
| **Check Flitt Status** | Any TBC order with a Flitt order ID | Queries Flitt API, displays status, auto-processes if approved |
| **Capture Payment** | Pre-authorized order (not yet captured) | Sends capture request to Flitt API, creates invoice |
| **Void Payment** | Pre-authorized order (not yet captured) | Cancels the Magento order; bank hold expires automatically |
| **Settle Payment** | Split payments enabled, not yet settled | Sends settlement request to distribute funds to receivers |

## Technical Architecture

### Key Classes

| Class | Purpose |
|---|---|
| `Gateway\Config\Config` | Configuration reader with typed accessors; signature generation |
| `Gateway\Http\Client\CreatePaymentClient` | Requests checkout tokens from Flitt `/api/checkout/token` |
| `Gateway\Http\Client\RefundClient` | Sends refund requests to Flitt `/api/reverse/order_id` |
| `Gateway\Http\Client\CaptureClient` | Captures pre-authorized payments via `/api/capture/order_id` |
| `Gateway\Http\Client\StatusClient` | Checks payment status via `/api/status/order_id` |
| `Gateway\Http\Client\SettlementClient` | Distributes funds via `/api/settlement` |
| `Gateway\Request\InitializeRequestBuilder` | Builds the checkout token request payload |
| `Gateway\Request\RefundRequestBuilder` | Builds the refund request payload |
| `Gateway\Request\SplitDataBuilder` | Adds split receiver data to the initialize request |
| `Gateway\Response\InitializeHandler` | Stores the Flitt token on the payment object |
| `Gateway\Response\RefundHandler` | Processes refund response, stores refund status |
| `Gateway\Validator\ResponseValidator` | Validates Flitt API `response_status` |
| `Gateway\Validator\CallbackValidator` | Verifies SHA1 callback signatures |
| `Controller\Payment\Params` | AJAX endpoint returning the Flitt checkout token |
| `Controller\Payment\Callback` | Server-to-server callback from Flitt |
| `Controller\Payment\Confirm` | Frontend confirmation after embed success |
| `Service\SettlementService` | Orchestrates split payment settlement |
| `Cron\PendingOrderReconciler` | Reconciles stuck pending orders |
| `Observer\SetPendingPaymentState` | Sets order to `pending_payment` on placement |
| `Plugin\AddSettleButton` | Adds admin toolbar buttons |
| `Model\Ui\ConfigProvider` | Provides checkout JS configuration |
| `Block\Payment\Info` | Renders payment details in admin |

### Magento Payment Gateway Pattern

The module uses Magento's Payment Gateway framework with virtual types:

- **Facade**: `ShuboTbcPaymentFacade` (virtual type of `Magento\Payment\Model\Method\Adapter`)
- **Command Pool**: `initialize` and `refund` commands
- **Request Builders**: Composite builder with `InitializeRequestBuilder` + `SplitDataBuilder`
- **HTTP Clients**: Direct cURL calls to the Flitt REST API
- **Response Handlers**: Store tokens, transaction IDs, and refund status
- **Validators**: `ResponseValidator` for API responses, `CallbackValidator` for signatures

### Events Dispatched

| Event | Purpose |
|---|---|
| `shubo_tbc_payment_split_data` | Allows modules to add split receivers to the payment request |
| `shubo_tbc_settlement_collect_receivers` | Allows modules to provide per-order settlement receivers |

## API Endpoints

### Flitt API Endpoints Used

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/checkout/token` | POST | Create checkout session token |
| `/api/status/order_id` | POST | Check payment status |
| `/api/reverse/order_id` | POST | Refund / reverse payment |
| `/api/capture/order_id` | POST | Capture pre-authorized payment |
| `/api/settlement` | POST | Distribute funds (split payments) |

Base URL: `https://pay.flitt.com` (same for sandbox and production; sandbox is controlled by merchant credentials).

### Module Frontend Routes

| URL | Method | Controller | Purpose |
|---|---|---|---|
| `/shubo_tbc/payment/params` | POST | `Params` | Get Flitt checkout token (AJAX) |
| `/shubo_tbc/payment/callback` | POST | `Callback` | Server-to-server callback (CSRF exempt) |
| `/shubo_tbc/payment/confirm` | POST | `Confirm` | Frontend payment confirmation (AJAX) |

### Module Admin Routes

| URL | Controller | Purpose |
|---|---|---|
| `/shubo_tbc/order/checkStatus` | `CheckStatus` | Query Flitt API and sync order status |
| `/shubo_tbc/order/capture` | `Capture` | Capture a pre-authorized payment |
| `/shubo_tbc/order/voidPayment` | `VoidPayment` | Void payment and cancel order |
| `/shubo_tbc/order/settle` | `Settle` | Trigger manual settlement |

## Logging

All module logs are written to a dedicated file:

```
var/log/shubo_tbc_payment.log
```

Enable **Debug** mode in configuration to log full API request/response bodies. Sensitive data (merchant ID, signature, password) is automatically masked in debug logs.

## Cron Jobs

| Job | Schedule | Purpose |
|---|---|---|
| `shubo_tbc_pending_order_reconciler` | Every 5 minutes | Checks orders in `pending_payment` state older than 15 minutes. Queries Flitt API and processes approved/declined/expired orders. Max 50 orders per run. |

## Internationalization

The module includes translations for:
- **English** (`en_US`)
- **Georgian** (`ka_GE`)

The Flitt embed card form language is automatically set based on the Magento store locale. Supported languages: `en`, `ka`, `ru`.

## Troubleshooting

### Payment form does not load
- Verify Merchant ID and Password are correctly set in configuration.
- Check the browser console for CSP errors. The module whitelists `pay.flitt.com` but custom CSP configurations may block it.
- Check `var/log/shubo_tbc_payment.log` with debug mode enabled.

### Order stuck in "pending_payment"
- Use the "Check Flitt Status" button in admin to manually query and sync.
- The cron reconciler should automatically process stuck orders after 15 minutes.
- Verify the callback URL (`/shubo_tbc/payment/callback`) is accessible from the internet.

### Refund fails
- Ensure the Flitt order ID is stored on the payment (`flitt_order_id` in additional info).
- Check `var/log/shubo_tbc_payment.log` for the Flitt API error response.
- Partial refunds are supported; the amount is sent in minor units (cents).

### Settlement fails
- Verify split payments are enabled and receivers are configured.
- Check that receiver Merchant IDs are valid Flitt merchants.
- Total percentages must not exceed 100%.
- Fixed amounts must not exceed the order total.
- Check logs for the Flitt settlement API response.

### Signature validation failed
- Ensure the Password (Secret Key) matches what is configured in the Flitt merchant dashboard.
- The signature is generated using all non-empty parameters sorted alphabetically.

## License

Apache License 2.0. See [LICENSE](LICENSE) for details.

Copyright 2026 Nikoloz Shubitidze (Shubo).
