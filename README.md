# BOG Payments

[![CI](https://github.com/gigoshkin/bog-payments-php/actions/workflows/ci.yml/badge.svg)](https://github.com/gigoshkin/bog-payments-php/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue?logo=php)](https://www.php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHPUnit](https://img.shields.io/badge/tested%20with-PHPUnit%2011-brightgreen)](https://phpunit.de)
[![PSR-18](https://img.shields.io/badge/PSR-18%20compatible-blue)](https://www.php-fig.org/psr/psr-18/)
[![codecov](https://codecov.io/github/gigoshkin/bog-payments-php/graph/badge.svg?token=QVZ8N7J23J)](https://codecov.io/github/gigoshkin/bog-payments-php)
[![Mutation testing badge](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2Fgigoshkin%2Fbog-payments-php%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/gigoshkin/bog-payments-php/main)

A standalone, framework-agnostic PHP 8.1+ client for the [Bank of Georgia Payments API](https://api.bog.ge/docs/en/payments/introduction). Zero framework coupling in production — drop it into Symfony, Laravel, or any PSR-compatible project by injecting standard interfaces.

---

## Requirements

- PHP 8.1+
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
    webhookPublicKey: file_get_contents('/path/to/bog-public-key.pem'), // optional, for webhook verification
);

// 2. Wire up (example with Guzzle)
$httpClient     = new \GuzzleHttp\Client();
$psr18Client    = new \GuzzleHttp\Psr7\HttpFactory(); // implements RequestFactoryInterface & StreamFactoryInterface

$client = BogClient::create($config, $httpClient, $psr18Client, $psr18Client);

// 3. Create an order
$order = $client->createOrder(new CreateOrderRequest(
    callbackUrl: 'https://your-shop.com/webhook/bog',
    totalAmount: 150.00,
    basket:      [
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
// Create an order — returns redirect URL for the payment portal
$response = $client->createOrder(CreateOrderRequest $request, ?string $idempotencyKey = null): CreateOrderResponse;
// $response->orderId      — BOG order ID
// $response->redirectUrl  — send the customer here
// $response->detailsUrl   — polling endpoint

// Fetch full receipt / status
$details = $client->getOrderDetails(string $orderId): OrderDetails;
// $details->status         — OrderStatus enum
// $details->processedAmount
// $details->buyerEmail
// $details->paymentMethod  — PaymentMethod enum
// $details->actions        — array of OrderAction
```

### Refunds

```php
// Full refund (omit amount)
$refund = $client->refund('order-id', new RefundRequest());

// Partial refund (cards, Apple Pay, Google Pay only)
$refund = $client->refund('order-id', new RefundRequest(amount: 25.00));

// $refund->key, $refund->message, $refund->actionId
```

### Recurring Payments (Saved Cards)

```php
// Step 1 — create an order with saveCard: true
$order = $client->createOrder(new CreateOrderRequest(
    callbackUrl: '...',
    totalAmount: 50.00,
    basket:      [...],
    saveCard:    true,
));

// Step 2 — after the customer pays, save the card token
$client->saveCard($order->orderId);

// Step 3 — charge later without customer interaction
// IMPORTANT: store the idempotency key before calling — use it on retry to avoid double-charges
$idempotencyKey = $myKeyGenerator->generate();
$newOrder = $client->chargeCard($order->orderId, new SubscribeRequest(
    callbackUrl:     'https://your-shop.com/webhook/bog',
    externalOrderId: 'SUBSCRIPTION-CYCLE-42',
), $idempotencyKey);

// Step 4 — remove a saved card
$client->deleteCard($order->orderId);
```

### Webhooks

```php
// In your webhook controller:
$rawBody        = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_CALLBACK_SIGNATURE'] ?? '';

try {
    $payload = $client->verifyAndParseWebhook($rawBody, $signatureHeader);
    // $payload->event       — e.g. 'order_payment'
    // $payload->order       — full OrderDetails DTO
    // $payload->requestTime — DateTimeImmutable

    match ($payload->order->status) {
        \Bog\Payments\Enum\OrderStatus::Completed => handleSuccess($payload->order),
        \Bog\Payments\Enum\OrderStatus::Refunded  => handleRefund($payload->order),
        default                                   => null,
    };

    http_response_code(200);
} catch (\Bog\Payments\Exception\WebhookVerificationException $e) {
    http_response_code(400);
}
```

---

## Advanced: Pre-Authorization (Manual Capture)

```php
use Bog\Payments\Enum\CaptureMode;

// Hold funds without charging (valid for 30 days)
$order = $client->createOrder(new CreateOrderRequest(
    callbackUrl: '...',
    totalAmount: 200.00,
    basket:      [...],
    capture:     CaptureMode::Manual,
));
// When the order status becomes 'pre_authorization_blocked', funds are held.
// Capture or cancel via the BOG merchant dashboard or future API calls.
```

## Advanced: Split Payments

```php
use Bog\Payments\Dto\Request\SplitTransfer;

$order = $client->createOrder(new CreateOrderRequest(
    callbackUrl:    '...',
    totalAmount:    100.00,
    basket:         [...],
    currency:       Currency::GEL,   // GEL only
    splitTransfers: [
        new SplitTransfer(iban: 'GE00000000000000000001', amount: 70.00),
        new SplitTransfer(iban: 'GE00000000000000000002', amount: 30.00),
    ],
));
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
            $config:          '@Bog\Payments\BogConfig'
            $httpClient:      '@psr18.client'
            $requestFactory:  '@Psr\Http\Message\RequestFactoryInterface'
            $streamFactory:   '@Psr\Http\Message\StreamFactoryInterface'
            $cache:           '@Bog\Payments\Cache\CacheInterface'
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
| `OrderNotFoundException` | 404 — order ID does not exist |
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
    // 404
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

# Unit and integration tests
php vendor/bin/phpunit --testdox

# Static analysis (PHPStan level 8)
php vendor/bin/phpstan analyse src --level=8

# Mutation testing (generates infection.log)
php -d pcov.enabled=1 -d pcov.directory=. vendor/bin/phpunit \
    --coverage-xml=build/coverage --log-junit=build/junit.xml
php vendor/bin/infection --coverage=build --threads=4
```

---

## License

MIT
