<?php

declare(strict_types=1);

namespace App\Auth;

use RuntimeException;

final class CertificateParser
{
    public function parse(string $pem): CertificateDetails
    {
        $trimmed = trim($pem);
        if ($trimmed === '') {
            throw new RuntimeException('Certificado não fornecido.');
        }

        if (!function_exists('openssl_x509_read') || !function_exists('openssl_x509_parse')) {
            throw new RuntimeException('Extensão OpenSSL não disponível no PHP.');
        }

        $resource = @openssl_x509_read($trimmed);
        if ($resource === false) {
            throw new RuntimeException('Não foi possível ler o certificado apresentado.');
        }

        try {
            $fingerprint = openssl_x509_fingerprint($resource, 'sha256');
            if ($fingerprint === false) {
                throw new RuntimeException('Não foi possível calcular a impressão digital do certificado.');
            }

            $data = openssl_x509_parse($resource, true);
            if ($data === false) {
                throw new RuntimeException('Não foi possível interpretar o certificado.');
            }

            $subject = (string)($data['name'] ?? '');
            $commonName = isset($data['subject']['CN']) ? (string)$data['subject']['CN'] : null;
            $serialNumber = isset($data['serialNumber']) ? (string)$data['serialNumber'] : null;
            $validFrom = isset($data['validFrom_time_t']) ? (int)$data['validFrom_time_t'] : null;
            $validTo = isset($data['validTo_time_t']) ? (int)$data['validTo_time_t'] : null;
            $cpf = $this->extractCpf($data);

            return new CertificateDetails(
                strtoupper($fingerprint),
                $subject,
                $commonName,
                $cpf,
                $serialNumber,
                $validFrom,
                $validTo,
                $trimmed
            );
        } finally {
            openssl_x509_free($resource);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractCpf(array $data): ?string
    {
        if (isset($data['subject']['serialNumber'])) {
            $candidate = digits_only((string)$data['subject']['serialNumber']);
            if (strlen($candidate) >= 11) {
                return substr($candidate, 0, 11);
            }
        }

        if (isset($data['subject']['CN'])) {
            $candidate = digits_only((string)$data['subject']['CN']);
            if (strlen($candidate) >= 11) {
                return substr($candidate, 0, 11);
            }
        }

        if (isset($data['extensions']['2.16.76.1.3.1'])) {
            $candidate = digits_only((string)$data['extensions']['2.16.76.1.3.1']);
            if (strlen($candidate) >= 11) {
                return substr($candidate, 0, 11);
            }
        }

        if (isset($data['extensions']['subjectAltName'])) {
            $candidate = digits_only((string)$data['extensions']['subjectAltName']);
            if (strlen($candidate) >= 11) {
                return substr($candidate, 0, 11);
            }
        }

        return null;
    }
}
