<?php

declare(strict_types=1);

namespace Bog\Payments\Auth;

use Bog\Payments\Exception\AuthenticationException;

interface TokenProviderInterface
{
    /**
     * Returns a valid Bearer access token string.
     *
     * @throws AuthenticationException if a token cannot be obtained.
     */
    public function getToken(): string;
}
