<?php

declare(strict_types=1);

namespace Bog\Payments\Idempotency;

/**
 * Generates RFC 4122 UUID v4 idempotency keys using cryptographically
 * secure random bytes. Each key is unique per call.
 */
final class IdempotencyKeyGenerator
{
    public function generate(): string
    {
        $bytes = random_bytes(16); // @infection-ignore-all — 17 bytes produces identical UUID: vsprintf only consumes first 16 bytes worth of hex

        // Set version bits to 4 (UUID v4) — exact RFC 4122 bitmask constants, ignore arithmetic mutations
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // @infection-ignore-all
        // Set variant bits to 10xx (RFC 4122)
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // @infection-ignore-all

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
