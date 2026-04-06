<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Unit\Auth;

use Bog\Payments\Auth\OAuthTokenProvider;
use Bog\Payments\BogConfig;
use Bog\Payments\Exception\AuthenticationException;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Boundary tests for HTTP status codes in OAuthTokenProvider.
 * These exist specifically to kill boundary mutations (e.g. >= 300 → > 300).
 */
final class OAuthTokenProviderBoundaryTest extends TestCase
{
    private Psr17Factory $factory;
    private BogConfig    $config;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->config  = new BogConfig('cid', 'secret');
    }

    private function makeProvider(MockClient $mock): OAuthTokenProvider
    {
        return new OAuthTokenProvider($this->config, $mock, $this->factory, $this->factory);
    }

    public function test_status_300_throws_authentication_exception(): void
    {
        // Body is valid JSON with access_token so that the mutation >= 300 → > 300
        // would treat 300 as success and return a token instead of throwing.
        $mock = new MockClient();
        $mock->addResponse(new Response(300, [], json_encode([
            'access_token' => 'tok',
            'expires_in'   => 3600,
        ])));

        $this->expectException(AuthenticationException::class);
        $this->makeProvider($mock)->getToken();
    }

    public function test_status_299_is_treated_as_success_when_body_valid(): void
    {
        // 299 is technically non-standard but satisfies >= 200 && < 300
        $mock = new MockClient();
        $mock->addResponse(new Response(299, [], json_encode([
            'access_token' => 'tok-299',
            'expires_in'   => 3600,
        ])));

        $provider = $this->makeProvider($mock);
        self::assertSame('tok-299', $provider->getToken());
    }

    public function test_status_402_throws_authentication_exception(): void
    {
        // 402 must fail (it's >= 300) even though it's not 401 or 403
        $mock = new MockClient();
        $mock->addResponse(new Response(402, [], 'Payment Required'));

        $this->expectException(AuthenticationException::class);
        $this->makeProvider($mock)->getToken();
    }

    public function test_status_404_throws_authentication_exception(): void
    {
        $mock = new MockClient();
        $mock->addResponse(new Response(404, [], 'Not Found'));

        $this->expectException(AuthenticationException::class);
        $this->makeProvider($mock)->getToken();
    }
}
