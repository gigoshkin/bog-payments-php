<?php

declare(strict_types=1);

namespace Bog\Payments;

final readonly class BogConfig
{
    public function __construct(
        public string  $clientId,
        #[\SensitiveParameter] public string  $clientSecret,
        public string  $baseUrl = 'https://api.bog.ge',
        public string  $tokenUrl = 'https://oauth2.bog.ge/auth/realms/bog/protocol/openid-connect/token',
        public int     $ttlBufferSeconds = 30, // @infection-ignore-all — ±1 on default value is noise: behaviour depends on expiry maths, not the default constant
        #[\SensitiveParameter] public ?string $webhookPublicKey = null,
    ) {
        if (trim($this->clientId) === '') { // @infection-ignore-all
            throw new \InvalidArgumentException('BogConfig: clientId must not be empty.');
        }
        if (trim($this->clientSecret) === '') { // @infection-ignore-all
            throw new \InvalidArgumentException('BogConfig: clientSecret must not be empty.');
        }
        if ($this->ttlBufferSeconds < 0) {
            throw new \InvalidArgumentException('BogConfig: ttlBufferSeconds must be >= 0.');
        }
    }
}
