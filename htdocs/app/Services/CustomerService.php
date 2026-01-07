<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CustomerServiceInterface;
use App\Repositories\ClientRepository;
use InvalidArgumentException;

class CustomerService implements CustomerServiceInterface
{
    private ClientRepository $clients;

    /** @var array<int, array<string, mixed>|null> */
    private array $cacheById = [];
    /** @var array<string, array<string, mixed>|null> */
    private array $cacheByDocument = [];
    /** @var array<string, array<string, mixed>|null> */
    private array $cacheByPhone = [];
    /** @var array<string, array<string, mixed>|null> */
    private array $cacheByEmail = [];

    public function __construct(?ClientRepository $clients = null)
    {
        $this->clients = $clients ?? new ClientRepository();
    }

    public function lookupByDocument(string $document): ?array
    {
        $digits = digits_only($document);
        if ($digits === '') {
            return null;
        }

        if (array_key_exists($digits, $this->cacheByDocument)) {
            return $this->cacheByDocument[$digits];
        }

        $client = $this->clients->findByDocument($digits);
        $normalized = $this->normalize($client);
        $this->cacheByDocument[$digits] = $normalized;
        if ($normalized !== null && isset($normalized['id'])) {
            $this->cacheById[(int)$normalized['id']] = $normalized;
        }

        return $normalized;
    }

    public function lookupByPhone(string $phone): ?array
    {
        $digits = digits_only($phone);
        if ($digits === '') {
            return null;
        }

        if (array_key_exists($digits, $this->cacheByPhone)) {
            return $this->cacheByPhone[$digits];
        }

        $client = $this->clients->findByPhoneDigits($digits);
        $normalized = $this->normalize($client);
        $this->cacheByPhone[$digits] = $normalized;
        if ($normalized !== null && isset($normalized['id'])) {
            $this->cacheById[(int)$normalized['id']] = $normalized;
        }

        return $normalized;
    }

    public function lookupByEmail(string $email): ?array
    {
        $needle = trim(mb_strtolower($email));
        if ($needle === '') {
            return null;
        }

        if (array_key_exists($needle, $this->cacheByEmail)) {
            return $this->cacheByEmail[$needle];
        }

        $client = $this->clients->findByEmail($needle);
        $normalized = $this->normalize($client);
        $this->cacheByEmail[$needle] = $normalized;
        if ($normalized !== null && isset($normalized['id'])) {
            $this->cacheById[(int)$normalized['id']] = $normalized;
        }

        return $normalized;
    }

    public function lookupBatch(array $identifiers): array
    {
        $results = [];

        foreach ($identifiers as $entry) {
            $doc = isset($entry['document']) ? (string)$entry['document'] : '';
            $phone = isset($entry['phone']) ? (string)$entry['phone'] : '';
            $email = isset($entry['email']) ? (string)$entry['email'] : '';

            $resolved = null;
            if ($doc !== '') {
                $resolved = $this->lookupByDocument($doc);
            }
            if ($resolved === null && $phone !== '') {
                $resolved = $this->lookupByPhone($phone);
            }
            if ($resolved === null && $email !== '') {
                $resolved = $this->lookupByEmail($email);
            }

            $results[] = $resolved;
        }

        return $results;
    }

    public function upsert(array $payload): int
    {
        $document = digits_only((string)($payload['document'] ?? ''));
        if ($document === '') {
            throw new InvalidArgumentException('Documento é obrigatório para cadastrar o cliente.');
        }

        $existing = $this->clients->findByDocument($document);
        $name = trim((string)($payload['name'] ?? ($existing['name'] ?? '')));
        if ($name === '') {
            $name = 'Cliente ' . $document;
        }

        $email = isset($payload['email']) ? trim((string)$payload['email']) : null;
        $phone = isset($payload['phone']) ? digits_only((string)$payload['phone']) : null;
        $whatsapp = isset($payload['whatsapp']) ? digits_only((string)$payload['whatsapp']) : null;

        if ($existing === null) {
            $data = [
                'document' => $document,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'whatsapp' => $whatsapp,
            ];

            $id = $this->clients->insert(array_filter($data, static fn($value) => $value !== null && $value !== ''));
            $client = $this->clients->find($id);
        } else {
            $update = [];
            if ($name !== '' && $name !== ($existing['name'] ?? '')) {
                $update['name'] = $name;
            }
            if ($email !== null && $email !== '' && $email !== ($existing['email'] ?? null)) {
                $update['email'] = $email;
            }
            if ($phone !== null && $phone !== '' && $phone !== digits_only((string)($existing['phone'] ?? ''))) {
                $update['phone'] = $phone;
            }
            if ($whatsapp !== null && $whatsapp !== '' && $whatsapp !== digits_only((string)($existing['whatsapp'] ?? ''))) {
                $update['whatsapp'] = $whatsapp;
            }

            if ($update !== []) {
                $this->clients->update((int)$existing['id'], $update);
            }

            $client = $this->clients->find((int)$existing['id']);
            $id = (int)$existing['id'];
        }

        $normalized = $this->normalize($client);
        if ($normalized !== null) {
            $this->cacheById[$id] = $normalized;
            $this->cacheByDocument[$document] = $normalized;
            if ($phone) {
                $this->cacheByPhone[$phone] = $normalized;
            }
            if ($email) {
                $this->cacheByEmail[mb_strtolower($email)] = $normalized;
            }
        }

        return $id;
    }

    public function getById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        if (array_key_exists($id, $this->cacheById)) {
            return $this->cacheById[$id];
        }

        $client = $this->clients->find($id);
        $normalized = $this->normalize($client);
        $this->cacheById[$id] = $normalized;
        return $normalized;
    }

    private function normalize(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $emails = [];
        if (!empty($row['email'])) {
            $emails[] = (string)$row['email'];
        }

        $phones = [];
        foreach (['phone', 'whatsapp'] as $field) {
            if (!empty($row[$field])) {
                $digits = digits_only((string)$row[$field]);
                if ($digits !== '') {
                    $phones[] = $digits;
                }
            }
        }

        if (!empty($row['extra_phones']) && is_string($row['extra_phones'])) {
            $decoded = json_decode($row['extra_phones'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $value) {
                    $digits = digits_only((string)$value);
                    if ($digits !== '') {
                        $phones[] = $digits;
                    }
                }
            }
        }

        $phones = array_values(array_unique($phones));

        return [
            'id' => isset($row['id']) ? (int)$row['id'] : null,
            'document' => $row['document'] ?? null,
            'name' => $row['name'] ?? null,
            'emails' => $emails,
            'phones' => $phones,
            'status' => $row['status'] ?? null,
            'created_at' => isset($row['created_at']) ? (int)$row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (int)$row['updated_at'] : null,
        ];
    }
}
