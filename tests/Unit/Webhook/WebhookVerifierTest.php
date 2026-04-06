<?php

declare(strict_types=1);

namespace Bog\Payments\Tests\Unit\Webhook;

use Bog\Payments\Exception\WebhookVerificationException;
use Bog\Payments\Webhook\WebhookVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookVerifierTest extends TestCase
{
    private string $privateKey   = '';
    private string $publicKeyPem = '';

    protected function setUp(): void
    {
        // Generate a fresh RSA keypair for each test
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $privateKey = '';
        openssl_pkey_export($resource, $privateKey);
        $this->privateKey = $privateKey;

        $details            = openssl_pkey_get_details($resource);
        $this->publicKeyPem = $details['key'];
    }

    private function sign(string $data): string
    {
        openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    public function test_valid_signature_passes(): void
    {
        $body      = '{"event":"order_payment","body":{"id":"ord-123","status":"completed"}}';
        $signature = $this->sign($body);

        $verifier = new WebhookVerifier($this->publicKeyPem);
        $verifier->verify($body, $signature);

        // No exception = pass
        $this->addToAssertionCount(1);
    }

    public function test_tampered_body_fails(): void
    {
        $body      = '{"event":"order_payment","body":{"id":"ord-123","status":"completed"}}';
        $tampered  = '{"event":"order_payment","body":{"id":"ord-123","status":"refunded"}}';
        $signature = $this->sign($body);

        $verifier = new WebhookVerifier($this->publicKeyPem);

        $this->expectException(WebhookVerificationException::class);
        $verifier->verify($tampered, $signature);
    }

    public function test_wrong_key_fails(): void
    {
        $body      = '{"event":"order_payment"}';
        $signature = $this->sign($body);

        // Generate a different keypair
        $otherResource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $otherDetails  = openssl_pkey_get_details($otherResource);
        $otherPublicKey = $otherDetails['key'];

        $verifier = new WebhookVerifier($otherPublicKey);

        $this->expectException(WebhookVerificationException::class);
        $verifier->verify($body, $signature);
    }

    public function test_empty_signature_fails(): void
    {
        $verifier = new WebhookVerifier($this->publicKeyPem);

        $this->expectException(WebhookVerificationException::class);
        $verifier->verify('{"event":"test"}', '');
    }

    public function test_invalid_base64_signature_fails(): void
    {
        $verifier = new WebhookVerifier($this->publicKeyPem);

        $this->expectException(WebhookVerificationException::class);
        $verifier->verify('{"event":"test"}', '!!!not-base64!!!');
    }

    public function test_empty_public_key_pem_throws_on_construction(): void
    {
        $this->expectException(WebhookVerificationException::class);
        new WebhookVerifier('');
    }

    public function test_invalid_pem_throws_on_construction(): void
    {
        $this->expectException(WebhookVerificationException::class);
        new WebhookVerifier('this is not a valid PEM');
    }

    public function test_url_encoded_base64_signature_passes(): void
    {
        $body      = '{"event":"order_payment"}';
        $signature = $this->sign($body);

        // Simulate proxy URL-encoding: base64 '+' → '%2B', '/' → '%2F', '=' → '%3D'
        $urlEncodedSig = str_replace(['+', '/', '='], ['%2B', '%2F', '%3D'], $signature);

        $verifier = new WebhookVerifier($this->publicKeyPem);
        $verifier->verify($body, $urlEncodedSig);

        $this->addToAssertionCount(1);
    }
}
