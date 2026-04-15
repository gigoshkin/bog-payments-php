# BOG Payments

[![CI](https://github.com/gigoshkin/bog-payments-php/actions/workflows/ci.yml/badge.svg)](https://github.com/gigoshkin/bog-payments-php/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue?logo=php)](https://www.php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHPUnit](https://img.shields.io/badge/tested%20with-PHPUnit%2011-brightgreen)](https://phpunit.de)
[![PSR-18](https://img.shields.io/badge/PSR-18%20compatible-blue)](https://www.php-fig.org/psr/psr-18/)
[![codecov](https://codecov.io/github/gigoshkin/bog-payments-php/graph/badge.svg?token=QVZ8N7J23J)](https://codecov.io/github/gigoshkin/bog-payments-php)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fgigoshkin%2Fbog-payments-php%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/gigoshkin/bog-payments-php/main)
[![Packagist Version](https://img.shields.io/packagist/v/gigoshkin/bog-payments-php)](https://packagist.org/packages/gigoshkin/bog-payments-php)

A standalone, framework-agnostic PHP 8.2+ client for the [Bank of Georgia Payments API](https://api.bog.ge/docs/en/payments/introduction). Zero framework coupling in production — drop it into Symfony, Laravel, or any PSR-compatible project by injecting standard interfaces.

---

## Requirements

- PHP 8.2+
- `ext-openssl`, `ext-json`
- A PSR-18 HTTP client (e.g. Guzzle, Symfony HttpClient)
- PSR-17 request/stream factories (e.g. Nyholm PSR-7, Guzzle PSR-7)

## Installation

```bash
composer require gigoshkin/bog-payments-php
```

You also need a concrete HTTP client (not included to keep the library decoupled):

```bash
# Option A — Guzzle
composer require guzzlehttp/guzzle guzzlehttp/psr7

# Option B — Symfony HttpClient
composer require symfony/http-client nyholm/psr7
```

---

## Quick Start

```php
use Bog\Payments\BogClient;
use Bog\Payments\BogConfig;
use Bog\Payments\Dto\Request\BasketItem;
use Bog\Payments\Dto\Request\CreateOrderRequest;
use Bog\Payments\Enum\Currency;

// 1. Configure
$config = new BogConfig(
    clientId:         'your-client-id',
    clientSecret:     'your-client-secret',
    webhookPublicKey: file_get_contents('/path/to/bog-public-key.pem'), // optional
);

// 2. Wire up (example with Guzzle)
$httpClient  = new \GuzzleHttp\Client();
$psr17Factory = new \GuzzleHttp\Psr7\HttpFactory();

$client = BogClient::create($config, $httpClient, $psr17Factory, $psr17Factory);

// 3. Create an order
$order = $client->createOrder(new CreateOrderRequest(
    callbackUrl:     'https://your-shop.com/webhook/bog',
    totalAmount:     150.00,
    basket:          [
        new BasketItem(productId: 'SKU-001', quantity: 2, unitPrice: 50.00, description: 'Widget'),
        new BasketItem(productId: 'SKU-002', quantity: 1, unitPrice: 50.00),
    ],
    currency:        Currency::GEL,
    externalOrderId: 'ORDER-12345',
));

// 4. Redirect the customer
header('Location: ' . $order->redirectUrl);
```

---

## Configuration

```php
$config = new BogConfig(
    clientId:         'your-client-id',      // required
    clientSecret:     'your-client-secret',  // required
    baseUrl:          'https://api.bog.ge',  // default
    tokenUrl:         'https://oauth2.bog.ge/auth/realms/bog/protocol/openid-connect/token', // default
    ttlBufferSeconds: 30,                    // shave off from token TTL to avoid mid-request expiry
    webhookPublicKey: '-----BEGIN PUBLIC KEY-----...', // PEM string; required for webhook verification
);
```

---

## API Reference

### Orders

```php
// Create an order — returns a redirect URL for the payment portal
$response = $client->createOrder(CreateOrderRequest $request, ?string $idempotencyKey = null): CreateOrderResponse;
// $response->orderId      — BOG order ID
// $response->redirectUrl  — send the customer here (null for Apple Pay / no-3DS Google Pay)
// $response->detailsUrl   — receipt polling URL
// $response->acceptUrl    — Apple Pay accept endpoint (null for standard orders)
// $response->status       — set when payment completes synchronously (e.g. no-3DS Google Pay)

// Fetch full receipt / payment status
$details = $client->getOrderDetails(string $orderId): OrderDetails;
// $details->id                     — BOG order ID
// $details->status                 — OrderStatus enum
// $details->currency               — Currency enum
// $details->requestedAmount        — amount requested at creation
// $details->processedAmount        — amount actually transferred / authorized
// $details->refundedAmount         — total refunded so far
// $details->createdAt              — DateTimeImmutable
// $details->expiresAt              — DateTimeImmutable
// $details->externalOrderId        — your system's order ID
// $details->capture                — "automatic" or "manual" (null if not returned)
// $details->paymentMethod          — PaymentMethod enum (null before payment)
// $details->maskedCard             — e.g. "400000***0001" (null before payment)
// $details->transactionId          — bank transaction ID
// $details->pgTrxId                — payment gateway transaction ID
// $details->cardType               — "visa", "mc", "amex" (null before payment)
// $details->cardExpiryDate         — "MM/YY" format
// $details->authCode               — payment authorization code
// $details->parentOrderId          — set when charged via a saved card
// $details->paymentOption          — "recurrent", "direct_debit", etc.
// $details->savedCardType          — "subscription", "recurrent", etc.
// $details->paymentCode            — PaymentStatusCode enum
// $details->paymentCodeDescription — human-readable code description
// $details->buyerName, ->buyerEmail, ->buyerPhone
// $details->rejectionReason        — "expiration", "unknown", or bank code description
// $details->actions                — array of OrderAction
```

#### CreateOrderRequest options

```php
new CreateOrderRequest(
    callbackUrl:         'https://your-shop.com/webhook/bog', // required
    totalAmount:         100.00,                              // required, > 0
    basket:              [new BasketItem(...)],               // required, at least one item
    currency:            Currency::GEL,                      // default GEL
    capture:             CaptureMode::Automatic,             // or Manual for pre-authorization
    externalOrderId:     'ORDER-123',
    redirectUrl:         'https://your-shop.com/success',    // success redirect
    failUrl:             'https://your-shop.com/fail',       // failure redirect
    ttl:                 30,                                  // minutes until expiry (2–1440)
    paymentMethods:      [PaymentMethod::Card, PaymentMethod::GooglePay], // restrict methods
    buyer:               new BuyerInfo(fullName: 'John Doe', maskedEmail: 'j***@example.com'),
    totalDiscountAmount: 10.00,
    deliveryAmount:      5.00,
    splitTransfers:      [new SplitTransfer('GE00...', 70.00), new SplitTransfer('GE00...', 30.00)],
    googlePay:           new ExternalGooglePayConfig($googlePayToken), // server-side Google Pay
    applePay:            new ExternalApplePayConfig(),                 // server-side Apple Pay
);
```

#### BasketItem options

```php
new BasketItem(
    productId:          'SKU-001',   // required
    quantity:           2,           // required
    unitPrice:          50.00,       // required
    description:        'Widget',
    unitDiscountPrice:  5.00,
    vat:                9.00,
    vatPercent:         18.0,
    totalPrice:         90.00,
    image:              'https://cdn.example.com/widget.jpg',
    packageCode:        'A000123',
    tin:                '123456789',
    pinfl:              '12345678901234',
    productDiscountId:  'PROMO2024',
);
```

---

### Refunds

```php
// Full refund (omit amount — works for card, Apple Pay, Google Pay, BOG authorization)
$result = $client->refund('order-id', new RefundRequest());

// Partial refund (cards, Apple Pay, Google Pay only)
$result = $client->refund('order-id', new RefundRequest(amount: 25.00));

// $result->key       — 'request_received'
// $result->message   — human-readable confirmation
// $result->actionId  — UUID; use same idempotency key to re-fetch same action

// Idempotent retry — same key = same actionId returned
$result = $client->refund('order-id', new RefundRequest(), idempotencyKey: 'my-idem-key');
```

---

### Google Pay (Server-Side / External)

When you collect the Google Pay token yourself (via the Google Pay JS SDK in your frontend), pass it directly to BOG:

```php
use Bog\Payments\Dto\Request\ExternalGooglePayConfig;

$order = $client->createOrder(new CreateOrderRequest(
    callbackUrl: 'https://your-shop.com/webhook/bog',
    totalAmount: 50.00,
    basket:      [...],
    googlePay:   new ExternalGooglePayConfig($googlePayToken), // token from Google Pay JS SDK
));

// If no 3DS is required, BOG completes the payment synchronously:
if ($order->status === 'completed') {
    // payment done — no redirect needed
} else {
    // 3DS required — redirect the customer
    header('Location: ' . $order->redirectUrl);
}
```

---

### Apple Pay (Server-Side / External)

When you run your own Apple Pay session and collect the payment token, pass it to BOG in two steps:

```php
use Bog\Payments\Dto\Request\ExternalApplePayConfig;

// Step 1 — create an Apple Pay order
$order = $client->createOrder(new CreateOrderRequest(
    callbackUrl: 'https://your-shop.com/webhook/bog',
    totalAmount: 50.00,
    basket:      [...],
    applePay:    new ExternalApplePayConfig(),
));
// $order->redirectUrl is null — Apple Pay orders don't redirect
// $order->acceptUrl   is the endpoint to POST the Apple Pay token to

// Step 2 — complete the payment with the token from Apple Pay JS
$result = $client->completeApplePayPayment(
    $order->orderId,
    $applePayToken, // from Apple Pay JS onpaymentauthorized event
);
// $result->status === 'completed' means payment succeeded
```

---

### Pre-Authorization (Manual Capture)

Pre-authorization blocks funds without charging. The order status becomes `OrderStatus::PreAuthorizationBlocked` once the customer pays. You then either confirm (capture) or cancel (release) the hold.

```php
use Bog\Payments\Dto\Request\CancelPreAuthRequest;
use Bog\Payments\Dto\Request\ConfirmPreAuthRequest;
use Bog\Payments\Enum\CaptureMode;

// Step 1 — create a manual-capture order
$order = $client->createOrder(new CreateOrderRequest(
    callbackUrl: 'https://your-shop.com/webhook/bog',
    totalAmount: 200.00,
    basket:      [...],
    capture:     CaptureMode::Manual,
));
// Redirect the customer to $order->redirectUrl
// When status = OrderStatus::PreAuthorizationBlocked, funds are held.

// Step 2a — confirm (capture) the full amount
$result = $client->confirmPreAuthorization(
    $order->orderId,
    new ConfirmPreAuthRequest(description: 'Order fulfilled'),
);

// Step 2b — confirm a partial amount
$result = $client->confirmPreAuthorization(
    $order->orderId,
    new ConfirmPreAuthRequest(amount: 150.00, description: 'Partial fulfillment'),
);

// Step 2c — cancel (release the hold entirely)
$result = $client->cancelPreAuthorization(
    $order->orderId,
    new CancelPreAuthRequest(description: 'Out of stock'),
);

// $result->key, $result->message, $result->actionId

// IMPORTANT: confirm and cancel are mutually exclusive on the same order.
// Each needs its own fresh pre-authorized order.
```

---

### Saved Cards

BOG supports two saved-card flows. In both cases the save call **must happen before redirecting the customer to the payment page** — BOG links the card during checkout.

#### Offline / Automatic charging (no customer interaction on future charges)

```php
use Bog\Payments\Dto\Request\SubscribeRequest;

// Step 1 — create an order
$order = $client->createOrder(new CreateOrderRequest(
    callbackUrl: 'https://your-shop.com/webhook/bog',
    totalAmount: 50.00,
    basket:      [...],
));

// Step 2 — register intent to save for automatic charging (BEFORE redirecting customer)
$client->saveCardAutomatic($order->orderId);

// Step 3 — redirect customer to $order->redirectUrl; they pay once

// Step 4 — charge without customer interaction on future billings
// IMPORTANT: store the idempotency key before calling — use it on retry to avoid double-charges
$idempotencyKey = $myKeyGenerator->generate();
$charged = $client->chargeCard(
    $order->orderId,
    new SubscribeRequest(
        callbackUrl:     'https://your-shop.com/webhook/bog',
        externalOrderId: 'SUBSCRIPTION-CYCLE-42',
    ),
    $idempotencyKey,
);
// $charged->orderId     — new order ID for this charge
// $charged->detailsUrl  — receipt URL
```

#### Recurrent / User-present charging (customer re-authenticates on future charges)

```php
// Step 1 — create an order
$order = $client->createOrder(new CreateOrderRequest(
    callbackUrl: 'https://your-shop.com/webhook/bog',
    totalAmount: 50.00,
    basket:      [...],
));

// Step 2 — register intent to save for recurrent charging (BEFORE redirecting customer)
$client->saveCard($order->orderId);

// Step 3 — redirect customer to $order->redirectUrl; they pay once

// Step 4 — create a new order using the saved card (customer will be redirected to re-authenticate)
$newOrder = $client->createRecurrentOrder(
    $order->orderId,                  // parent order ID where card was saved
    new CreateOrderRequest(
        callbackUrl:     'https://your-shop.com/webhook/bog',
        totalAmount:     50.00,
        basket:          [...],
        externalOrderId: 'RENEWAL-' . $cycleId,
    ),
);
// Redirect customer to $newOrder->redirectUrl
```

#### Delete a saved card

```php
$client->deleteCard($order->orderId);
```

---

### Webhooks

```php
// In your webhook controller:
$rawBody         = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_CALLBACK_SIGNATURE'] ?? '';

try {
    $payload = $client->verifyAndParseWebhook($rawBody, $signatureHeader);
    // $payload->event       — e.g. 'order_payment'
    // $payload->order       — full OrderDetails DTO
    // $payload->requestTime — DateTimeImmutable

    match ($payload->order->status) {
        OrderStatus::Completed        => handleSuccess($payload->order),
        OrderStatus::Refunded         => handleRefund($payload->order),
        OrderStatus::PartiallyRefunded => handlePartialRefund($payload->order),
        default                        => null,
    };

    http_response_code(200);
} catch (\Bog\Payments\Exception\WebhookVerificationException $e) {
    http_response_code(400);
}
```

If you don't need signature verification (e.g. IP-allowlisted endpoint):

```php
$payload = $client->parseWebhook($rawBody);
```

---

## Caching

Token caching is controlled via `CacheInterface`. The default `InMemoryCache` is single-process only. For production multi-process environments (PHP-FPM, RoadRunner) supply a shared cache:

```php
use Bog\Payments\Cache\CacheInterface;

class RedisCacheAdapter implements CacheInterface
{
    public function __construct(private \Redis $redis) {}

    public function get(string $key): mixed
    {
        $value = $this->redis->get($key);
        return $value === false ? null : unserialize($value);
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $this->redis->setex($key, $ttl, serialize($value));
    }

    public function delete(string $key): void
    {
        $this->redis->del($key);
    }
}

$client = BogClient::create($config, $httpClient, $requestFactory, $streamFactory, new RedisCacheAdapter($redis));
```

---

## Framework Integration

### Symfony (`services.yaml`)

```yaml
services:
    Bog\Payments\BogConfig:
        arguments:
            $clientId:         '%env(BOG_CLIENT_ID)%'
            $clientSecret:     '%env(BOG_CLIENT_SECRET)%'
            $webhookPublicKey: '%env(BOG_WEBHOOK_PUBLIC_KEY)%'

    Bog\Payments\BogClient:
        factory: ['Bog\Payments\BogClient', 'create']
        arguments:
            $config:         '@Bog\Payments\BogConfig'
            $httpClient:     '@psr18.client'
            $requestFactory: '@Psr\Http\Message\RequestFactoryInterface'
            $streamFactory:  '@Psr\Http\Message\StreamFactoryInterface'
            $cache:          '@Bog\Payments\Cache\CacheInterface'
```

### Laravel (Service Provider)

```php
use Bog\Payments\BogClient;
use Bog\Payments\BogConfig;

$this->app->singleton(BogClient::class, function ($app) {
    $config = new BogConfig(
        clientId:         config('bog.client_id'),
        clientSecret:     config('bog.client_secret'),
        webhookPublicKey: config('bog.webhook_public_key'),
    );

    return BogClient::create(
        $config,
        $app->make(\GuzzleHttp\Client::class),
        new \GuzzleHttp\Psr7\HttpFactory(),
        new \GuzzleHttp\Psr7\HttpFactory(),
        $app->make(\Bog\Payments\Cache\CacheInterface::class),
    );
});
```

---

## Error Handling

All exceptions extend `Bog\Payments\Exception\BogException`.

| Exception | When |
|-----------|------|
| `AuthenticationException` | Invalid credentials or persistent 401 after token refresh |
| `OrderNotFoundException` | Order ID not found (BOG returns 400 "Invalid order_id" — normalized to this) |
| `ApiException` | Any other non-2xx response; exposes `$statusCode` and `$responseBody` |
| `NetworkException` | PSR-18 transport failure (timeout, DNS, etc.) |
| `WebhookVerificationException` | Signature mismatch or missing public key |

```php
use Bog\Payments\Exception\ApiException;
use Bog\Payments\Exception\NetworkException;
use Bog\Payments\Exception\OrderNotFoundException;

try {
    $details = $client->getOrderDetails($orderId);
} catch (OrderNotFoundException $e) {
    // order does not exist
} catch (ApiException $e) {
    // $e->statusCode, $e->responseBody
} catch (NetworkException $e) {
    // retry or alert
}
```

---

## Running Tests

```bash
composer install

# Unit and integration tests (no credentials needed)
./vendor/bin/phpunit --testsuite unit,integration --testdox

# Static analysis (PHPStan level 8)
./vendor/bin/phpstan analyse src --level=8

# Mutation testing
php -d pcov.enabled=1 -d pcov.directory=. ./vendor/bin/phpunit \
    --testsuite unit,integration \
    --coverage-xml=build/coverage --log-junit=build/junit.xml
./vendor/bin/infection --coverage=build --threads=4
```

### Sandbox tests

Real HTTP tests against the BOG sandbox environment. Requires sandbox credentials from [businessmanager.bog.ge](https://businessmanager.bog.ge).

```bash
# Copy the example env file and fill in your credentials
cp .env.sandbox.example .env.sandbox
# BOG_SANDBOX_CLIENT_ID=...
# BOG_SANDBOX_CLIENT_SECRET=...

# Run all automated sandbox tests
./vendor/bin/phpunit --testsuite sandbox --testdox

# Run interactive end-to-end flow tests (requires a real terminal — prompts you to pay in a browser)
./vendor/bin/phpunit --testsuite sandbox-interactive --testdox
```

Interactive tests cover the full payment lifecycle — they create orders, print a URL for you to open in your browser, wait for you to complete payment, then assert the resulting state. They auto-skip in CI environments (no TTY detected).

---

## License

MIT
