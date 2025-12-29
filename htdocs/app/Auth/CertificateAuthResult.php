<?php

declare(strict_types=1);

namespace App\Auth;

final class CertificateAuthResult
{
    /**
     * @param 'approved'|'pending'|'denied'|'missing'|'invalid' $status
     */
    public function __construct(
        public readonly string $status,
        public readonly ?AuthenticatedUser $user,
        public readonly ?array $accessRequest,
        public readonly ?CertificateDetails $certificate,
        public readonly ?string $message = null
    ) {
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved' && $this->user !== null;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isDenied(): bool
    {
        return $this->status === 'denied';
    }

    public function isMissing(): bool
    {
        return $this->status === 'missing';
    }

    public function isInvalid(): bool
    {
        return $this->status === 'invalid';
    }
}
