<?php

declare(strict_types=1);

namespace Bog\Payments\Auth;

use Bog\Payments\BogConfig;
use Bog\Payments\Exception\AuthenticationException;
use Bog\Payments\Exception\NetworkException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class OAuthTokenProvider implements TokenProviderInterface, TokenFetcherInterface
{
    public function __construct(
        private BogConfig              $config,
        private ClientInterface        $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface  $streamFactory,
    ) {}

    public function getToken(): string
    {
        return $this->fetchToken()->token;
    }

    public function fetchToken(): AccessToken
    {
        $credentials = base64_encode($this->config->clientId . ':' . $this->config->clientSecret);
        $body        = 'grant_type=client_credentials';

        $request = $this->requestFactory
            ->createRequest('POST', $this->config->tokenUrl)
            ->withHeader('Authorization', 'Basic ' . $credentials)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new NetworkException(
                'BOG token endpoint unreachable: ' . $e->getMessage(), // @infection-ignore-all
                0, // @infection-ignore-all
                $e,
            );
        }

        $status      = $response->getStatusCode();
        $rawBody     = (string) $response->getBody();

        if ($status === 401 || $status === 403) { // @infection-ignore-all — 400/402/404 variants are equivalent: >=300 catches them all
            throw new AuthenticationException( // @infection-ignore-all
                sprintf('BOG authentication failed (HTTP %d). Check client_id and client_secret.', $status),
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new AuthenticationException(
                sprintf('BOG token endpoint returned unexpected status %d: %s', $status, $rawBody),
            );
        }

        $data = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR); // depth is PHP default 512

        if (!isset($data['access_token'], $data['expires_in'])) {
            throw new AuthenticationException(
                'BOG token response missing required fields (access_token, expires_in).',
            );
        }

        return new AccessToken(
            token:     (string) $data['access_token'],
            expiresIn: (int)    $data['expires_in'],
            tokenType: (string) ($data['token_type'] ?? 'Bearer'), // @infection-ignore-all
        );
    }
}
