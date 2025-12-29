<?php

declare(strict_types=1);

namespace App\Auth;

final class AuthenticatedUser
{
    /** @param string[] $permissions */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $role,
        public readonly string $fingerprint,
        public readonly ?string $cpf,
        public readonly array $permissions = [],
        public readonly ?string $sessionIp = null,
        public readonly ?string $sessionLocation = null,
        public readonly ?string $sessionUserAgent = null,
        public readonly ?int $sessionStartedAt = null,
        public readonly ?int $lastSeenAt = null,
        public readonly ?int $accessWindowStart = null,
        public readonly ?int $accessWindowEnd = null,
        public readonly bool $requireKnownDevice = false,
        public readonly string $clientAccessScope = 'all',
        public readonly bool $allowOnlineClients = false,
        public readonly bool $allowInternalChat = true,
        public readonly bool $allowExternalChat = true,
        public readonly bool $isAvp = false,
        public readonly ?string $avpIdentityLabel = null,
        public readonly ?string $avpIdentityCpf = null,
        public readonly ?string $chatIdentifier = null,
        public readonly ?string $chatDisplayName = null
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function can(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($permission, $this->permissions, true);
    }
}
