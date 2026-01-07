<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Customer identity facade for cross-module interactions.
 * Returned shape: id, document, name, emails[], phones[], status, created_at, updated_at.
 */
interface CustomerServiceInterface
{
    public function lookupByDocument(string $document): ?array;

    public function lookupByPhone(string $phone): ?array;

    public function lookupByEmail(string $email): ?array;

    /**
     * @param array<int, array{document?:string, phone?:string, email?:string}> $identifiers
     * @return array<int, array|null> Each entry mirrors lookup* shape or null.
     */
    public function lookupBatch(array $identifiers): array;

    /**
     * @param array<string, mixed> $payload Basic attributes (document, name, emails, phones...).
     * @return int Customer id (created or updated).
     */
    public function upsert(array $payload): int;

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array;
}
