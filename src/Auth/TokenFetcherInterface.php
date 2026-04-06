<?php

declare(strict_types=1);

namespace Bog\Payments\Auth;

use Bog\Payments\Exception\AuthenticationException;
use Bog\Payments\Exception\NetworkException;

interface TokenFetcherInterface
{
    /**
     * Fetch a fresh access token from the authorization server.
     *
     * @throws AuthenticationException if credentials are rejected.
     * @throws NetworkException if the token endpoint is unreachable.
     */
    public function fetchToken(): AccessToken;
}
