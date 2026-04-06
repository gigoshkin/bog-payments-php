<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Unit\Auth;

use Bog\Payments\Auth\OAuthTokenProvider;
use Bog\Payments\BogConfig;
use Bog\Payments\Exception\AuthenticationException;
use Bog\Payments\Exception\NetworkException;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class OAuthTokenProviderTest extends TestCase
{
    private Psr17Factory $factory;
    private BogConfig    $config;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->config  = new BogConfig('my-client-id', 'my-client-secret');
    }

    private function makeProvider(MockClient $mock): OAuthTokenProvider
    {
        return new OAuthTokenProvider($this->config, $mock, $this->factory, $this->factory);
    }

    public function test_fetches_token_on_success(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], json_encode([
            'access_token' => 'test-token-abc',
            'expires_in'   => 3600,
            'token_type'   => 'Bearer',
        ])));

        $provider = $this->makeProvider($mock);
        self::assertSame('test-token-abc', $provider->getToken());
    }

    public function test_sends_basic_auth_header(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], json_encode([
            'access_token' => 'tok',
            'expires_in'   => 3600,
        ])));

        $provider = $this->makeProvider($mock);
        $provider->getToken();

        $sentRequest  = $mock->getLastRequest();
        $authHeader   = $sentRequest->getHeaderLine('Authorization');
        $expected     = 'Basic ' . base64_encode('my-client-id:my-client-secret');

        self::assertSame($expected, $authHeader);
    }

    public function test_sends_correct_content_type(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], json_encode([
            'access_token' => 'tok',
            'expires_in'   => 3600,
        ])));

        $provider = $this->makeProvider($mock);
        $provider->getToken();

        $sentRequest = $mock->getLastRequest();
        self::assertSame(
            'application/x-www-form-urlencoded',
            $sentRequest->getHeaderLine('Content-Type'),
        );
    }

    public function test_sends_grant_type_in_body(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], json_encode([
            'access_token' => 'tok',
            'expires_in'   => 3600,
        ])));

        $provider = $this->makeProvider($mock);
        $provider->getToken();

        $body = (string) $mock->getLastRequest()->getBody();
        self::assertStringContainsString('grant_type=client_credentials', $body);
    }

    public function test_throws_authentication_exception_on_401(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(401, [], 'Unauthorized'));

        $provider = $this->makeProvider($mock);

        $this->expectException(AuthenticationException::class);
        $provider->getToken();
    }

    public function test_throws_authentication_exception_on_403(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(403, [], 'Forbidden'));

        $provider = $this->makeProvider($mock);

        $this->expectException(AuthenticationException::class);
        $provider->getToken();
    }

    public function test_throws_authentication_exception_on_malformed_json(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], 'not json'));

        $provider = $this->makeProvider($mock);

        $this->expectException(\JsonException::class);
        $provider->getToken();
    }

    public function test_throws_authentication_exception_on_missing_access_token_field(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(200, [], json_encode(['expires_in' => 3600])));

        $provider = $this->makeProvider($mock);

        $this->expectException(AuthenticationException::class);
        $provider->getToken();
    }

    public function test_throws_network_exception_on_client_error(): void
    {
        $networkException = new class('Network error') extends \RuntimeException implements ClientExceptionInterface {};

        $mock = new MockClient();
        $mock->addException($networkException);

        $provider = $this->makeProvider($mock);

        $this->expectException(NetworkException::class);
        $provider->getToken();
    }

    public function test_throws_on_unexpected_5xx(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(500, [], 'Server Error'));

        $provider = $this->makeProvider($mock);

        $this->expectException(AuthenticationException::class);
        $provider->getToken();
    }
}
