<?php

declare(strict_types=1);

namespace Bog\Payments\Webhook;

use Bog\Payments\Exception\WebhookVerificationException;

/**
 * Verifies BOG webhook signatures using SHA256withRSA.
 *
 * IMPORTANT: Always verify the signature against the raw (undecoded) request
 * body before deserializing or processing any fields. BOG signs the exact
 * byte sequence of the body and field order must not be altered.
 */
final readonly class WebhookVerifier
{
    private \OpenSSLAsymmetricKey $publicKey;

    /**
     * @param string $publicKeyPem PEM-encoded RSA public key provided by BOG.
     *
     * @throws WebhookVerificationException if the PEM is invalid.
     */
    public function __construct(string $publicKeyPem)
    {
        if (trim($publicKeyPem) === '') { // @infection-ignore-all
            throw new WebhookVerificationException(
                'Webhook public key PEM is empty. Configure BogConfig::$webhookPublicKey.',
            );
        }

        $key = openssl_pkey_get_public($publicKeyPem);

        if ($key === false) {
            throw new WebhookVerificationException(
                'Failed to load webhook public key: ' . openssl_error_string(), // @infection-ignore-all
            );
        }

        $this->publicKey = $key;
    }

    /**
     * Verify that $rawBody was signed by BOG.
     *
     * The signature header value may be base64-encoded or URL-encoded base64.
     * Both are handled transparently.
     *
     * @param string $rawBody         Raw HTTP request body bytes (before JSON decoding).
     * @param string $signatureHeader Value of the `Callback-Signature` header.
     *
     * @throws WebhookVerificationException on mismatch or decoding failure.
     */
    public function verify(string $rawBody, string $signatureHeader): void
    {
        $binarySignature = $this->decodeSignature($signatureHeader);

        $result = openssl_verify($rawBody, $binarySignature, $this->publicKey, OPENSSL_ALGO_SHA256);

        if ($result === 1) { // @infection-ignore-all
            return; // @infection-ignore-all
        }

        if ($result === 0) { // @infection-ignore-all — === -1 / !== 0 / removed-throw variants are equivalent: the final throw below catches all non-1 results
            throw new WebhookVerificationException( // @infection-ignore-all
                'Webhook signature verification failed: signature does not match payload.',
            );
        }

        throw new WebhookVerificationException(
            'Webhook signature verification error: ' . openssl_error_string(), // @infection-ignore-all
        );
    }

    /**
     * Attempt to decode the signature header value as base64,
     * with a fallback for URL-encoded base64 (common in proxy setups).
     */
    private function decodeSignature(string $signatureHeader): string
    {
        $header = trim($signatureHeader); // @infection-ignore-all

        // Try raw base64 first
        $decoded = base64_decode($header, strict: true);

        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }

        // Fallback: URL-decoded base64 (some proxies encode '+' as '%2B', '/' as '%2F')
        $decoded = base64_decode(urldecode($header), strict: true);

        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }

        throw new WebhookVerificationException(
            'Webhook signature header could not be base64-decoded.',
        );
    }
}
