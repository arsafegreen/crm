<?php

declare(strict_types=1);

namespace App\Auth;

final class OperationResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $message = null
    ) {
    }

    public static function success(?string $message = null): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
