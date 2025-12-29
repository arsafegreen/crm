<?php

declare(strict_types=1);

namespace App\Auth;

final class CertificateDetails
{
    public function __construct(
        public readonly string $fingerprint,
        public readonly string $subject,
        public readonly ?string $commonName,
        public readonly ?string $cpf,
        public readonly ?string $serialNumber,
        public readonly ?int $validFrom,
        public readonly ?int $validTo,
        public readonly string $rawPem
    ) {
    }
}
