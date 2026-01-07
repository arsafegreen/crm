<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CertificateServiceInterface;
use App\Repositories\CertificateRepository;
use InvalidArgumentException;

class CertificateService implements CertificateServiceInterface
{
    private CertificateRepository $certificates;

    public function __construct(?CertificateRepository $certificates = null)
    {
        $this->certificates = $certificates ?? new CertificateRepository();
    }

    public function latestForCustomer(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }

        return $this->certificates->latestForClient($customerId);
    }

    public function listByCustomer(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $rows = $this->certificates->forClients([$customerId]);
        $filtered = array_filter($rows, static function (array $row) use ($customerId): bool {
            return isset($row['client_id']) && (int)$row['client_id'] === $customerId;
        });

        return array_values($filtered);
    }

    public function issue(array $payload): int
    {
        $data = $this->preparePayload($payload);
        if ($data['protocol'] === '') {
            throw new InvalidArgumentException('Protocol is required');
        }
        if ($data['client_id'] <= 0) {
            throw new InvalidArgumentException('customer_id (client_id) is required');
        }

        $existing = $this->certificates->findByProtocol($data['protocol']);
        if ($existing !== null) {
            $this->certificates->update((int)$existing['id'], $data);
            return (int)$existing['id'];
        }

        return $this->certificates->insert($data);
    }

    public function renew(array $payload): int
    {
        return $this->issue($payload);
    }

    public function partnerStats(?int $startAt, ?int $endAt, ?int $limit = 10, string $sortField = 'latest', string $sortDirection = 'desc'): array
    {
        return $this->certificates->partnerStats($startAt, $endAt, $limit, $sortField, $sortDirection);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function preparePayload(array $payload): array
    {
        $allowed = [
            'client_id', 'protocol', 'product_name', 'product_description', 'validity_label', 'media_description',
            'serial_number', 'status', 'is_revoked', 'revocation_reason', 'start_at', 'end_at', 'avp_name', 'avp_cpf',
            'aci_name', 'aci_cpf', 'location_name', 'location_alias', 'city_name', 'emission_type', 'requested_type',
            'partner_name', 'partner_accountant', 'partner_accountant_plus', 'renewal_protocol', 'status_raw', 'source_payload',
        ];

        $data = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = $payload[$key];
            }
        }

        $data['protocol'] = isset($data['protocol']) ? (string)$data['protocol'] : '';
        $data['client_id'] = isset($data['client_id']) ? (int)$data['client_id'] : 0;
        $data['is_revoked'] = isset($data['is_revoked']) ? (int)$data['is_revoked'] : 0;

        return $data;
    }
}
