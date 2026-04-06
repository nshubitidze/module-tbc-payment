# Shubo_TbcPayment — TBC Bank (Flitt Embed) for Magento 2

[![Latest Stable Version](https://img.shields.io/packagist/v/shubo/module-tbc-payment.svg)](https://packagist.org/packages/shubo/module-tbc-payment)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

Accept card payments in your Magento 2 store via TBC Bank's Flitt Embed SDK. Supports embedded checkout, online refunds, SHA1 signature verification, and multi-vendor split payments.

## Features

- **Embedded checkout** — Flitt Embed SDK renders the payment form directly on the checkout page (no redirect)
- **Online refunds** — process full refunds from the Magento admin
- **Split payments** — distribute funds across multiple Flitt merchant accounts for marketplace orders
- **Signature verification** — SHA1-based request signing and callback validation
- **Sandbox mode** — separate sandbox API URL for testing
- **Multi-locale** — auto-detects Georgian (ka), Russian (ru), or English (en) from the store locale
- **Debug logging** — optional PSR-3 structured logging for API requests/responses

## Requirements

| Dependency | Version |
|---|---|
| PHP | >= 8.1 |
| Magento Framework | >= 103.0 |
| Magento_Payment | >= 100.4 |
| Magento_Sales | >= 103.0 |
| Magento_Checkout | >= 100.4 |
| Magento_Quote | >= 101.2 |

Compatible with Magento 2.4.x (Open Source and Commerce).

## Installation

```bash
composer require shubo/module-tbc-payment
bin/magento module:enable Shubo_TbcPayment
bin/magento setup:upgrade
bin/magento cache:flush
```

## Configuration

Navigate to **Stores > Configuration > Sales > Payment Methods > TBC Bank (Flitt Embed)**.

| Field | Description | Scope |
|---|---|---|
| **Enabled** | Activate/deactivate the payment method | Website |
| **Title** | Payment method name shown at checkout (default: "TBC Bank (Card Payment)") | Store View |
| **Merchant ID** | Your Flitt merchant ID | Website |
| **Password (Secret Key)** | Flitt secret key (stored encrypted) | Website |
| **Sandbox Mode** | Toggle between sandbox and production environments | Website |
| **Sandbox API URL** | Sandbox endpoint (default: `https://pay.flitt.com`) | Default |
| **Production API URL** | Production endpoint (default: `https://pay.flitt.com`) | Default |
| **Debug** | Enable detailed logging to `var/log/` | Website |
| **Sort Order** | Display order among payment methods | Website |
| **Enable Split Payments** | Allow payment splitting across multiple merchants | Website |

## Payment Flow

1. Customer selects "TBC Bank" at checkout
2. Frontend JS requests a payment token via `shubo_tbc/payment/gettoken`
3. The `CreatePaymentClient` calls Flitt API to create a payment session (with signature)
4. Flitt Embed SDK renders the card form inline using the returned token
5. Customer completes payment inside the embedded form
6. Flitt sends a server-to-server callback to `shubo_tbc/payment/callback`
7. `CallbackValidator` verifies the SHA1 signature against the secret key
8. Order is updated to the appropriate status

### Signature Generation

All API requests are signed using SHA1. Parameters are filtered (empty values removed), sorted alphabetically by key, prepended with the secret key, joined with `|`, and hashed:

```
SHA1(secret_key|param1_value|param2_value|...)
```

## Split Payments

When **Enable Split Payments** is turned on, the module dispatches the event `shubo_tbc_payment_split_data` before sending the payment request to Flitt. External modules (e.g., a marketplace module) can observe this event to provide split receivers.

### Implementing a Split Payment Observer

```php
// In your module's etc/events.xml (or etc/frontend/events.xml)
<event name="shubo_tbc_payment_split_data">
    <observer name="my_module_tbc_split" instance="Vendor\Module\Observer\TbcSplitObserver"/>
</event>
```

```php
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Shubo\TbcPayment\Model\SplitPaymentData;

class TbcSplitObserver implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        $transport = $observer->getData('transport');
        $receivers = [];

        $split = new SplitPaymentData();
        $split->setMerchantId('MERCHANT_FLITT_ID')
              ->setAmount(5000)       // amount in minor units (e.g., 50.00 GEL = 5000)
              ->setCurrency('GEL')
              ->setDescription('Vendor payout');

        $receivers[] = $split;
        $transport->setData('receivers', $receivers);
    }
}
```

Each receiver requires: `merchantId` (Flitt sub-merchant), `amount` (integer, minor units), `currency`, and `description`.

## Module Structure

```
Shubo/TbcPayment/
  Api/Data/SplitPaymentDataInterface.php
  Block/Payment/Info.php
  Controller/Payment/Callback.php
  Controller/Payment/GetToken.php
  Gateway/
    Config/Config.php
    Exception/FlittApiException.php
    Helper/SubjectReader.php
    Http/Client/CreatePaymentClient.php
    Http/Client/RefundClient.php
    Http/TransferFactory.php
    Request/InitializeRequestBuilder.php
    Request/RefundRequestBuilder.php
    Request/SplitDataBuilder.php
    Response/InitializeHandler.php
    Response/RefundHandler.php
    Validator/CallbackValidator.php
    Validator/ResponseValidator.php
  Model/SplitPaymentData.php
  Model/Ui/ConfigProvider.php
  view/frontend/
    layout/checkout_index_index.xml
    requirejs-config.js
    web/js/view/payment/method-renderer.js
    web/js/view/payment/shubo-tbc.js
```

## Testing

```bash
# Coding standards
vendor/bin/phpcs --standard=Magento2 app/code/Shubo/TbcPayment/

# Static analysis
vendor/bin/phpstan analyse -l 8 app/code/Shubo/TbcPayment/

# Unit tests
vendor/bin/phpunit -c phpunit.xml --filter Shubo_TbcPayment
```

## License

[MIT](LICENSE)
