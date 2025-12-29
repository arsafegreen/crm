<?php

declare(strict_types=1);

namespace App\Auth;

final class AuthAttemptResult
{
    private function __construct(
        public readonly bool $success,
        public readonly bool $requiresTotp,
        public readonly ?int $userId,
        public readonly ?string $message = null
    ) {
    }

    public static function success(int $userId): self
    {
        return new self(true, false, $userId);
    }

    public static function pendingTotp(): self
    {
        return new self(false, true, null);
    }

    public static function failure(string $message): self
    {
        return new self(false, false, null, $message);
    }
}
