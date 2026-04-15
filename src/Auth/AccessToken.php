<?php

declare(strict_types=1);

namespace Bog\Payments\Auth;

final readonly class AccessToken
{
    public function __construct(
        #[\SensitiveParameter] public string $token,
        public int    $expiresIn,
        public string $tokenType = 'Bearer',
    ) {}
}
