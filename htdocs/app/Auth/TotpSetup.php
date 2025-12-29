<?php

declare(strict_types=1);

namespace App\Auth;

final class TotpSetup
{
    public function __construct(
        public readonly string $secret,
        public readonly string $uri
    ) {
    }
}
