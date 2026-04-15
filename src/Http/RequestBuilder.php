<?php

declare(strict_types=1);

namespace Bog\Payments\Http;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds authenticated PSR-7 requests for the BOG API.
 */
final readonly class RequestBuilder
{
    public function __construct(
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface  $streamFactory,
    ) {}

    /**
     * Build a JSON request with Bearer auth and optional idempotency key.
     *
     * @param array<string, mixed> $body
     */
    public function json(
        string  $method,
        string  $url,
        #[\SensitiveParameter] string  $token,
        array   $body = [],
        ?string $idempotencyKey = null,
    ): RequestInterface {
        $upper   = strtoupper($method); // @infection-ignore-all
        $request = $this->requestFactory
            ->createRequest($upper, $url)
            ->withHeader('Authorization', 'Bearer ' . $token) // @infection-ignore-all
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json');

        if ($idempotencyKey !== null) {
            $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        }

        if (in_array($upper, ['POST', 'PUT', 'PATCH'], true)) {
            $request = $request->withBody(
                $this->streamFactory->createStream(
                    json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), // @infection-ignore-all
                ),
            );
        }

        return $request;
    }

    /**
     * Build a request with no body (GET, DELETE).
     */
    public function plain(string $method, string $url, #[\SensitiveParameter] string $token, ?string $idempotencyKey = null): RequestInterface
    {
        $upper   = strtoupper($method); // @infection-ignore-all
        $request = $this->requestFactory
            ->createRequest($upper, $url)
            ->withHeader('Authorization', 'Bearer ' . $token) // @infection-ignore-all
            ->withHeader('Accept', 'application/json');

        if ($idempotencyKey !== null) {
            $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
        }

        return $request;
    }
}
