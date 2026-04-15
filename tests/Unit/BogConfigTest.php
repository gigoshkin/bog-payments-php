<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Unit;

use Bog\Payments\BogConfig;
use PHPUnit\Framework\TestCase;

final class BogConfigTest extends TestCase
{
    public function test_stores_required_fields(): void
    {
        $config = new BogConfig('my-id', 'my-secret');

        self::assertSame('my-id', $config->clientId);
        self::assertSame('my-secret', $config->clientSecret);
    }

    public function test_default_base_url(): void
    {
        $config = new BogConfig('id', 'secret');

        self::assertSame('https://api.bog.ge', $config->baseUrl);
    }

    public function test_default_token_url(): void
    {
        $config = new BogConfig('id', 'secret');

        self::assertSame(
            'https://oauth2.bog.ge/auth/realms/bog/protocol/openid-connect/token',
            $config->tokenUrl,
        );
    }

    public function test_default_ttl_buffer(): void
    {
        $config = new BogConfig('id', 'secret');

        self::assertSame(30, $config->ttlBufferSeconds);
    }

    public function test_webhook_public_key_null_by_default(): void
    {
        $config = new BogConfig('id', 'secret');

        self::assertNull($config->webhookPublicKey);
    }

    public function test_custom_values_override_defaults(): void
    {
        $config = new BogConfig(
            clientId:         'cid',
            clientSecret:     'csecret',
            baseUrl:          'https://api-sandbox.bog.ge',
            tokenUrl:         'https://oauth2-sandbox.bog.ge/auth/realms/bog/protocol/openid-connect/token',
            ttlBufferSeconds: 10,
            webhookPublicKey: '-----BEGIN PUBLIC KEY-----',
        );

        self::assertSame('https://api-sandbox.bog.ge', $config->baseUrl);
        self::assertSame('https://oauth2-sandbox.bog.ge/auth/realms/bog/protocol/openid-connect/token', $config->tokenUrl);
        self::assertSame(10, $config->ttlBufferSeconds);
        self::assertSame('-----BEGIN PUBLIC KEY-----', $config->webhookPublicKey);
    }

    public function test_throws_on_empty_client_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('clientId');
        new BogConfig('', 'secret');
    }

    public function test_throws_on_whitespace_client_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BogConfig('   ', 'secret');
    }

    public function test_throws_on_empty_client_secret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('clientSecret');
        new BogConfig('id', '');
    }

    public function test_throws_on_whitespace_client_secret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BogConfig('id', '   ');
    }

    public function test_throws_on_negative_ttl_buffer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ttlBufferSeconds');
        new BogConfig('id', 'secret', ttlBufferSeconds: -1);
    }

    public function test_zero_ttl_buffer_is_valid(): void
    {
        $this->expectNotToPerformAssertions();
        new BogConfig('id', 'secret', ttlBufferSeconds: 0);
    }
}
