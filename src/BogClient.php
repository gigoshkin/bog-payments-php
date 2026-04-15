<?php

declare(strict_types=1);

namespace Bog\Payments;

use Bog\Payments\Auth\CachedTokenProvider;
use Bog\Payments\Auth\OAuthTokenProvider;
use Bog\Payments\Auth\TokenProviderInterface;
use Bog\Payments\Cache\CacheInterface;
use Bog\Payments\Cache\InMemoryCache;
use Bog\Payments\Dto\Request\CancelPreAuthRequest;
use Bog\Payments\Dto\Request\ConfirmPreAuthRequest;
use Bog\Payments\Dto\Request\CreateOrderRequest;
use Bog\Payments\Dto\Request\RefundRequest;
use Bog\Payments\Dto\Request\SubscribeRequest;
use Bog\Payments\Dto\Response\CreateOrderResponse;
use Bog\Payments\Dto\Response\OrderDetails;
use Bog\Payments\Dto\Response\RefundResponse;
use Bog\Payments\Dto\Response\SubscribeResponse;
use Bog\Payments\Dto\Response\WebhookPayload;
use Bog\Payments\Exception\ApiException;
use Bog\Payments\Exception\AuthenticationException;
use Bog\Payments\Exception\NetworkException;
use Bog\Payments\Exception\OrderNotFoundException;
use Bog\Payments\Exception\WebhookVerificationException;
use Bog\Payments\Http\RequestBuilder;
use Bog\Payments\Idempotency\IdempotencyKeyGenerator;
use Bog\Payments\Webhook\WebhookVerifier;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class BogClient
{
    public function __construct(
        private readonly BogConfig               $config,
        private readonly TokenProviderInterface  $tokenProvider,
        private readonly ClientInterface         $httpClient,
        private readonly RequestBuilder          $requestBuilder,
        private readonly IdempotencyKeyGenerator $idempotencyKeyGen,
        private readonly ?WebhookVerifier        $webhookVerifier = null,
    ) {}

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Convenience factory that wires all dependencies automatically.
     *
     * Supply a CacheInterface for production multi-process environments
     * (e.g., a Redis-backed adapter). Defaults to InMemoryCache (single-process).
     */
    public static function create(
        BogConfig               $config,
        ClientInterface         $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface  $streamFactory,
        ?CacheInterface         $cache = null,
    ): self {
        $cache ??= new InMemoryCache(); // @infection-ignore-all

        $oauthProvider = new OAuthTokenProvider($config, $httpClient, $requestFactory, $streamFactory);
        $tokenProvider = new CachedTokenProvider($oauthProvider, $cache, $config);
        $requestBuilder = new RequestBuilder($requestFactory, $streamFactory);
        $idempotencyKeyGen = new IdempotencyKeyGenerator();

        $webhookVerifier = null;
        if ($config->webhookPublicKey !== null) {
            $webhookVerifier = new WebhookVerifier($config->webhookPublicKey);
        }

        return new self($config, $tokenProvider, $httpClient, $requestBuilder, $idempotencyKeyGen, $webhookVerifier);
    }

    // -------------------------------------------------------------------------
    // Orders
    // -------------------------------------------------------------------------

    /**
     * Create a new payment order.
     *
     * An idempotency key is auto-generated if not provided.
     * To safely retry a failed request, supply the same key you used originally.
     *
     * @throws AuthenticationException
     * @throws ApiException
     * @throws NetworkException
     */
    public function createOrder(CreateOrderRequest $request, ?string $idempotencyKey = null): CreateOrderResponse
    {
        $key     = $idempotencyKey ?? $this->idempotencyKeyGen->generate();
        $token   = $this->resolveToken();
        $url     = $this->config->baseUrl . '/payments/v1/ecommerce/orders';

        $psrRequest = $this->requestBuilder->json('POST', $url, $token, $request->toArray(), $key);
        $data       = $this->send($psrRequest);

        return CreateOrderResponse::fromArray($data);
    }

    /**
     * Retrieve full order details / payment receipt.
     *
     * @throws AuthenticationException
     * @throws OrderNotFoundException
     * @throws ApiException
     * @throws NetworkException
     */
    public function getOrderDetails(string $orderId): OrderDetails
    {
        $token = $this->resolveToken();
        $url   = $this->config->baseUrl . '/payments/v1/receipt/' . rawurlencode($orderId);

        $psrRequest = $this->requestBuilder->plain('GET', $url, $token);

        try {
            $data = $this->send($psrRequest, $orderId);
        } catch (ApiException $e) {
            // BOG returns 400 "Invalid order_id" for orders that don't exist in their system,
            // rather than 404. Normalise this to OrderNotFoundException for callers.
            if ($e->statusCode === 400 && stripos($e->responseBody, 'order_id') !== false) {
                throw new OrderNotFoundException(sprintf('BOG order "%s" not found.', $orderId), 0, $e);
            }
            throw $e;
        }

        return OrderDetails::fromArray($data);
    }

    // -------------------------------------------------------------------------
    // Refunds
    // -------------------------------------------------------------------------

    /**
     * Refund an order, fully or partially.
     *
     * Pass RefundRequest with null amount for a full refund.
     * Partial refunds are only supported for card, Apple Pay, and Google Pay.
     *
     * An idempotency key is auto-generated if not provided.
     *
     * @throws AuthenticationException
     * @throws OrderNotFoundException
     * @throws ApiException
     * @throws NetworkException
     */
    public function refund(string $orderId, RefundRequest $request, ?string $idempotencyKey = null): RefundResponse
    {
        $key   = $idempotencyKey ?? $this->idempotencyKeyGen->generate();
        $token = $this->resolveToken();
        $url   = $this->config->baseUrl . '/payments/v1/payment/refund/' . rawurlencode($orderId);

        $psrRequest = $this->requestBuilder->json('POST', $url, $token, $request->toArray(), $key);
        $data       = $this->send($psrRequest, $orderId);

        return RefundResponse::fromArray($data);
    }

    // -------------------------------------------------------------------------
    // Pre-authorization
    // -------------------------------------------------------------------------

    /**
     * Confirm a pre-authorized payment, fully or partially.
     *
     * Pass null amount to confirm the full pre-authorized amount.
     * Pass a partial amount for partial confirmation.
     *
     * @throws AuthenticationException
     * @throws OrderNotFoundException
     * @throws ApiException
     * @throws NetworkException
     */
    public function confirmPreAuthorization(
        string               $orderId,
        ConfirmPreAuthRequest $request,
        ?string              $idempotencyKey = null,
    ): RefundResponse {
        $key   = $idempotencyKey ?? $this->idempotencyKeyGen->generate();
        $token = $this->resolveToken();
        $url   = $this->config->baseUrl . '/payments/v1/payment/authorization/approve/' . rawurlencode($orderId);

        $psrRequest = $this->requestBuilder->json('POST', $url, $token, $request->toArray(), $key);
        $data       = $this->send($psrRequest, $orderId);

        return RefundResponse::fromArray($data);
    }

    /**
     * Cancel (reject) a pre-authorized payment.
     *
     * @throws AuthenticationException
     * @throws OrderNotFoundException
     * @throws ApiException
     * @throws NetworkException
     */
    public function cancelPreAuthorization(
        string              $orderId,
        CancelPreAuthRequest $request,
        ?string             $idempotencyKey = null,
    ): RefundResponse {
        $key   = $idempotencyKey ?? $this->idempotencyKeyGen->generate();
        $token = $this->resolveToken();
        $url   = $this->config->baseUrl . '/payments/v1/payment/authorization/cancel/' . rawurlencode($orderId);

        $psrRequest = $this->requestBuilder->json('POST', $url, $token, $request->toArray(), $key);
        $data       = $this->send($psrRequest, $orderId);

        return RefundResponse::fromArray($data);
    }

    // -------------------------------------------------------------------------
    // Recurring / Saved cards
    // -------------------------------------------------------------------------

    /**
     * Save the card used in an existing order for future user-initiated (recurring) charges.
     * The order must have been completed successfully.
     *
     * @throws AuthenticationException
     * @throws OrderNotFoundException
     * @throws ApiException
     * @throws NetworkException
     */
    public function saveCard(string $orderId, ?string $idempotencyKey = null): void
    {
        $key   = $idempotencyKey ?? $this->idempotencyKeyGen->generate();
        $token = $this->resolveToken();
        $url   = $this->config->baseUrl . '/payments/v1/orders/' . rawurlencode($orderId) . '/cards';

        $psrRequest = $this->requestBuilder->plain('PUT', $url, $token, $key);
        $this->send($psrRequest, $orderId, expectBody: false); // @infection-ignore-all — true is equivalent: status 202 + empty body returns [] via other conditions
    }

    /**
     * Save the card used in an existing order for future automatic (offline) charges.
     * Call this BEFORE redirecting the customer to the payment page.
     * Upon successful payment, the card is linked to this order ID for future chargeCard() calls.
     *
     * @throws AuthenticationException
     * @throws OrderNotFoundException
     * @throws ApiException
     * @throws NetworkException
     */
    public function saveCardAutomatic(string $orderId, ?string $idempotencyKey = null): void
    {
        $key   = $idempotencyKey ?? $this->idempotencyKeyGen->generate();
        $token = $this->resolveToken();
        $url   = $this->config->baseUrl . '/payments/v1/orders/' . rawurlencode($orderId) . '/subscriptions';

        $psrRequest = $this->requestBuilder->plain('PUT', $url, $token, $key);
        $this->send($psrRequest, $orderId, expectBody: false); // @infection-ignore-all
    }

    /**
     * Delete a previously saved card.
     *
     * @throws AuthenticationException
     * @throws OrderNotFoundException
     * @throws ApiException
     * @throws NetworkException
     */
    public function deleteCard(string $orderId): void
    {
        $token = $this->resolveToken();
        $url   = $this->config->baseUrl . '/payments/v1/charges/card/' . rawurlencode($orderId);

        $psrRequest = $this->requestBuilder->plain('DELETE', $url, $token);
        $this->send($psrRequest, $orderId, expectBody: false); // @infection-ignore-all — true is equivalent: status 202 + empty body returns [] via other conditions
    }

    /**
     * Create a new order using a saved card (user-present / recurring flow).
     * The customer will be redirected to BOG's payment page to authenticate.
     *
     * @throws AuthenticationException
     * @throws OrderNotFoundException
     * @throws ApiException
     * @throws NetworkException
     */
    public function createRecurrentOrder(
        string             $parentOrderId,
        CreateOrderRequest $request,
        ?string            $idempotencyKey = null,
    ): CreateOrderResponse {
        $key   = $idempotencyKey ?? $this->idempotencyKeyGen->generate();
        $token = $this->resolveToken();
        $url   = $this->config->baseUrl
            . '/payments/v1/ecommerce/orders/'
            . rawurlencode($parentOrderId);

        $psrRequest = $this->requestBuilder->json('POST', $url, $token, $request->toArray(), $key);
        $data       = $this->send($psrRequest, $parentOrderId);

        return CreateOrderResponse::fromArray($data);
    }

    /**
     * Complete an Apple Pay payment initiated on the merchant's own webpage.
     *
     * Flow:
     *   1. Create an order with ExternalApplePayConfig — response contains $acceptUrl.
     *   2. Call this method with that order ID and the apple_pay_token from the Apple Pay JS SDK.
     *   3. Check the returned response: if status is 'completed', payment succeeded.
     *      If a redirectUrl is present, redirect the customer there for 3DS.
     *
     * @see https://api.bog.ge/docs/en/payments/external-orders/complete-external-applepay
     *
     * @throws AuthenticationException
     * @throws OrderNotFoundException
     * @throws ApiException
     * @throws NetworkException
     */
    public function completeApplePayPayment(
        string  $orderId,
        string  $applePayToken,
        ?string $idempotencyKey = null,
    ): CreateOrderResponse {
        $key   = $idempotencyKey ?? $this->idempotencyKeyGen->generate();
        $token = $this->resolveToken();
        $url   = $this->config->baseUrl
            . '/payments/v1/ecommerce/orders/'
            . rawurlencode($orderId)
            . '/payment';

        $psrRequest = $this->requestBuilder->json('POST', $url, $token, [
            'apple_pay_token' => $applePayToken,
        ], $key);
        $data = $this->send($psrRequest, $orderId);

        return CreateOrderResponse::fromArray($data);
    }

    /**
     * Automatically charge a saved card without customer interaction (offline flow).
     *
     * IMPORTANT: If you need to retry a failed charge call, you MUST supply the
     * same idempotency key you used originally. Auto-generation on each call
     * would create a duplicate charge. Store the key before calling this method.
     *
     * @throws AuthenticationException
     * @throws OrderNotFoundException
     * @throws ApiException
     * @throws NetworkException
     */
    public function chargeCard(
        string           $parentOrderId,
        SubscribeRequest $request,
        ?string          $idempotencyKey = null,
    ): SubscribeResponse {
        $key   = $idempotencyKey ?? $this->idempotencyKeyGen->generate();
        $token = $this->resolveToken();
        $url   = $this->config->baseUrl
            . '/payments/v1/ecommerce/orders/'
            . rawurlencode($parentOrderId)
            . '/subscribe';

        $psrRequest = $this->requestBuilder->json('POST', $url, $token, $request->toArray(), $key);
        $data       = $this->send($psrRequest, $parentOrderId);

        return SubscribeResponse::fromArray($data);
    }

    // -------------------------------------------------------------------------
    // Webhooks
    // -------------------------------------------------------------------------

    /**
     * Verify the Callback-Signature header and parse the webhook body.
     *
     * The signature is verified before any deserialization occurs.
     * Always call this method before trusting any webhook data.
     *
     * @param string $rawBody         The raw HTTP request body (not decoded).
     * @param string $signatureHeader The value of the `Callback-Signature` header.
     *
     * @throws WebhookVerificationException if the signature is invalid or the key is not configured.
     */
    public function verifyAndParseWebhook(string $rawBody, string $signatureHeader): WebhookPayload
    {
        if ($this->webhookVerifier === null) {
            throw new WebhookVerificationException(
                'Webhook verification is not configured. Set BogConfig::$webhookPublicKey.',
            );
        }

        $this->webhookVerifier->verify($rawBody, $signatureHeader);

        $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

        return WebhookPayload::fromArray($data);
    }

    /**
     * Parse a webhook payload without signature verification.
     *
     * Use this only in trusted environments (e.g., IP-whitelisted endpoints).
     * Prefer verifyAndParseWebhook() for production use.
     */
    public function parseWebhook(string $rawBody): WebhookPayload
    {
        $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        return WebhookPayload::fromArray($data);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Get a valid token. If the cached token provider supports invalidation,
     * this is used on 401 to retry once.
     */
    private function resolveToken(): string
    {
        return $this->tokenProvider->getToken();
    }

    /**
     * Send a PSR-7 request and return the decoded JSON body.
     * Handles 401 token-invalidation retry when tokenProvider is CachedTokenProvider.
     *
     * @return array<string, mixed>
     *
     * @throws AuthenticationException
     * @throws OrderNotFoundException
     * @throws ApiException
     * @throws NetworkException
     */
    private function send(
        \Psr\Http\Message\RequestInterface $request,
        ?string $orderId = null,
        bool $expectBody = true,
    ): array {
        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new NetworkException('BOG API request failed: ' . $e->getMessage(), 0, $e); // @infection-ignore-all
        }

        $status  = $response->getStatusCode();
        $rawBody = (string) $response->getBody();

        if ($status === 401) {
            // Try refreshing the token once if the provider supports it
            if ($this->tokenProvider instanceof CachedTokenProvider) {
                $newToken = $this->tokenProvider->invalidateAndRefresh();

                // Rebuild request with new token
                $request = $request->withHeader('Authorization', 'Bearer ' . $newToken); // @infection-ignore-all

                try {
                    $response = $this->httpClient->sendRequest($request);
                } catch (ClientExceptionInterface $e) {
                    throw new NetworkException('BOG API request failed on token refresh retry: ' . $e->getMessage(), 0, $e); // @infection-ignore-all
                }

                $status  = $response->getStatusCode();
                $rawBody = (string) $response->getBody();

                if ($status === 401) {
                    throw new AuthenticationException(
                        'BOG API authentication failed after token refresh. Check your credentials.',
                    );
                }
            } else {
                throw new AuthenticationException('BOG API returned 401 Unauthorized.');
            }
        }

        if ($status === 404) {
            $msg = $orderId !== null // @infection-ignore-all
                ? sprintf('BOG order "%s" not found.', $orderId)
                : 'BOG resource not found.';
            throw new OrderNotFoundException($msg);
        }

        if ($status < 200 || $status >= 300) {
            throw new ApiException(
                sprintf('BOG API error (HTTP %d): %s', $status, $rawBody),
                $status,
                $rawBody,
            );
        }

        if (!$expectBody || $rawBody === '' || $status === 204) { // @infection-ignore-all — LogicalOr/±1 variants are equivalent: multiple overlapping early-return conditions
            return [];
        }

        return json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR); // depth is PHP default 512
    }
}
