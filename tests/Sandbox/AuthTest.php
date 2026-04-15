<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Sandbox;

use Bog\Payments\Auth\OAuthTokenProvider;
use Bog\Payments\BogConfig;
use Http\Client\Curl\Client as CurlClient;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Verifies that OAuth token fetch works against the real sandbox.
 */
final class AuthTest extends SandboxTestCase
{
    public function test_fetch_token_returns_non_empty_string(): void
    {
        $clientId     = (string) ($_ENV['BOG_SANDBOX_CLIENT_ID']     ?? getenv('BOG_SANDBOX_CLIENT_ID'));
        $clientSecret = (string) ($_ENV['BOG_SANDBOX_CLIENT_SECRET'] ?? getenv('BOG_SANDBOX_CLIENT_SECRET'));

        $config   = new BogConfig($clientId, $clientSecret, self::BASE_URL, self::TOKEN_URL);
        $factory  = new Psr17Factory();
        $http     = new CurlClient(null, null, [CURLOPT_ENCODING => '']);
        $provider = new OAuthTokenProvider($config, $http, $factory, $factory);

        $token = $provider->fetchToken();

        self::assertNotEmpty($token->token, 'access_token must not be empty');
        self::assertGreaterThan(0, $token->expiresIn, 'expires_in must be positive');
        self::assertSame('Bearer', $token->tokenType);
    }

    public function test_client_instance_is_ready(): void
    {
        // Confirms that makeClient() builds without error using real sandbox credentials.
        // Actual token fetch is validated by test_fetch_token_returns_non_empty_string above.
        self::assertInstanceOf(\Bog\Payments\BogClient::class, $this->makeClient());
    }
}
