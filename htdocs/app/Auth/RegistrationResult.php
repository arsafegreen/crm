<?php

declare(strict_types=1);

namespace App\Auth;

final class RegistrationResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $message = null
    ) {
    }

    public static function success(): self
    {
        return new self(true);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
