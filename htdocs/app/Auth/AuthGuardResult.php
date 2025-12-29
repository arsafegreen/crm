<?php

declare(strict_types=1);

namespace App\Auth;

use Symfony\Component\HttpFoundation\Response;

final class AuthGuardResult
{
    public function __construct(
        public readonly ?AuthenticatedUser $user,
        public readonly ?Response $response
    ) {
    }

    public static function public(): self
    {
        return new self(null, null);
    }

    public static function authenticated(AuthenticatedUser $user): self
    {
        return new self($user, null);
    }

    public static function intercepted(Response $response): self
    {
        return new self(null, $response);
    }
}
