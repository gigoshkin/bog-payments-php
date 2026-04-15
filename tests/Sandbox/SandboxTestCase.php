<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Sandbox;

use Bog\Payments\BogClient;
use Bog\Payments\BogConfig;
use Bog\Payments\Cache\InMemoryCache;
use Dotenv\Dotenv;
use Http\Client\Curl\Client as CurlClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/**
 * Base class for sandbox integration tests.
 *
 * These tests make real HTTP requests to the BOG sandbox environment.
 * They are excluded from the default test run and must be invoked explicitly:
 *
 *   ./vendor/bin/phpunit --testsuite sandbox
 *
 * Required environment variables (set in shell or .env.sandbox):
 *   BOG_SANDBOX_CLIENT_ID      — your sandbox client_id
 *   BOG_SANDBOX_CLIENT_SECRET  — your sandbox client_secret
 *
 * Quickest setup:
 *   export BOG_SANDBOX_CLIENT_ID=...
 *   export BOG_SANDBOX_CLIENT_SECRET=...
 *   ./vendor/bin/phpunit --testsuite sandbox
 *
 * Or copy .env.sandbox.example → .env.sandbox and fill in your credentials.
 */
abstract class SandboxTestCase extends TestCase
{
    protected const BASE_URL  = 'https://api-sandbox.bog.ge';
    protected const TOKEN_URL = 'https://oauth2-sandbox.bog.ge/auth/realms/bog/protocol/openid-connect/token';

    /**
     * Minimum pause between sandbox API calls (milliseconds).
     * Keeps us well within BOG rate limits.
     */
    private const RATE_LIMIT_DELAY_MS = 500;

    /** Card that succeeds for all operations */
    protected const CARD_SUCCESS  = '4000000000000001';
    /** Card that is declined (insufficient funds) */
    protected const CARD_DECLINED = '4000000000000002';
    /** Card that succeeds on first payment then rejects subsequent operations */
    protected const CARD_THEN_REJECT = '4000000000000003';

    /**
     * Shared client — one token fetch per test run, not per test.
     * Also ensures we reuse the same HTTP connection pool.
     */
    private static ?BogClient $sharedClient = null;

    protected function setUp(): void
    {
        $this->loadEnvFile();

        $clientId     = $_ENV['BOG_SANDBOX_CLIENT_ID']     ?? getenv('BOG_SANDBOX_CLIENT_ID')     ?: '';
        $clientSecret = $_ENV['BOG_SANDBOX_CLIENT_SECRET'] ?? getenv('BOG_SANDBOX_CLIENT_SECRET') ?: '';

        if ($clientId === '' || $clientSecret === '') {
            $this->markTestSkipped(
                'Sandbox credentials not set. Add to .env.sandbox or export BOG_SANDBOX_CLIENT_ID and BOG_SANDBOX_CLIENT_SECRET.',
            );
        }
    }

    /**
     * Returns the shared sandbox client.
     *
     * The client is created once per process — it caches the OAuth token
     * internally, so subsequent calls do not re-fetch a token. A 500 ms
     * inter-test delay (tearDown) keeps us well within BOG sandbox rate limits.
     */
    protected function makeClient(): BogClient
    {
        if (self::$sharedClient === null) {
            $clientId     = (string) ($_ENV['BOG_SANDBOX_CLIENT_ID']     ?? getenv('BOG_SANDBOX_CLIENT_ID'));
            $clientSecret = (string) ($_ENV['BOG_SANDBOX_CLIENT_SECRET'] ?? getenv('BOG_SANDBOX_CLIENT_SECRET'));

            $config  = new BogConfig($clientId, $clientSecret, self::BASE_URL, self::TOKEN_URL);
            $factory = new Psr17Factory();
            $http    = new CurlClient(null, null, [
                CURLOPT_ENCODING => '', // accept and auto-decompress gzip/deflate responses
            ]);

            self::$sharedClient = BogClient::create($config, $http, $factory, $factory, new InMemoryCache());
        }

        return self::$sharedClient;
    }

    protected function tearDown(): void
    {
        // Pause between tests to avoid hitting sandbox rate limits.
        usleep(self::RATE_LIMIT_DELAY_MS * 1000);
    }

    /**
     * Load .env.sandbox if it exists. Shell environment variables always
     * take priority (Dotenv::createImmutable does not overwrite them).
     */
    private function loadEnvFile(): void
    {
        $root = dirname(__DIR__, 2);

        if (!is_file($root . '/.env.sandbox')) {
            return;
        }

        Dotenv::createImmutable($root, '.env.sandbox')->safeLoad();
    }

}
