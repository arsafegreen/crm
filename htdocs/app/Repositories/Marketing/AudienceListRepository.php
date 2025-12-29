<?php

declare(strict_types=1);

namespace App\Repositories\Marketing;

use App\Database\Connection;
use App\Repositories\Marketing\MarketingContactRepository;
use PDO;

final class AudienceListRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance('marketing');
    }

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM audience_lists ORDER BY name ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function allWithStats(): array
    {
        $sql = 'SELECT l.*, 
                    (SELECT COUNT(*) FROM audience_list_contacts lc WHERE lc.list_id = l.id) AS contacts_total,
                    (SELECT COUNT(*) FROM audience_list_contacts lc WHERE lc.list_id = l.id AND lc.subscription_status = "subscribed") AS contacts_subscribed,
                    (SELECT COUNT(*) FROM audience_list_contacts lc WHERE lc.list_id = l.id AND lc.subscription_status = "unsubscribed") AS contacts_unsubscribed,
                    (SELECT COUNT(DISTINCT lc.contact_id) FROM audience_list_contacts lc INNER JOIN mail_delivery_logs mdl ON mdl.contact_id = lc.contact_id WHERE lc.list_id = l.id AND mdl.event_type = "sent") AS contacts_sent,
                    (SELECT COUNT(DISTINCT lc.contact_id) FROM audience_list_contacts lc INNER JOIN mail_delivery_logs mdl ON mdl.contact_id = lc.contact_id WHERE lc.list_id = l.id AND mdl.event_type = "soft_bounce") AS contacts_soft_bounce,
                    (SELECT COUNT(DISTINCT lc.contact_id) FROM audience_list_contacts lc INNER JOIN mail_delivery_logs mdl ON mdl.contact_id = lc.contact_id WHERE lc.list_id = l.id AND mdl.event_type = "hard_bounce") AS contacts_hard_bounce
                FROM audience_lists l
                ORDER BY l.created_at DESC';

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audience_lists WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audience_lists WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record !== false ? $record : null;
    }

    public function upsert(array $payload): int
    {
        $slug = trim((string)($payload['slug'] ?? ''));
        if ($slug === '') {
            throw new \InvalidArgumentException('Slug é obrigatório para criar/atualizar lista.');
        }

        $existing = $this->findBySlug($slug);
        if ($existing === null) {
            return $this->create($payload);
        }

        $this->update((int)$existing['id'], $payload);
        return (int)$existing['id'];
    }

    public function attachEmails(int $listId, array $emails): void
    {
        if ($emails === []) {
            return;
        }

        $contactRepo = new MarketingContactRepository($this->pdo);
        foreach ($emails as $email) {
            $normalized = trim(mb_strtolower((string)$email));
            if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $contact = $contactRepo->findByEmail($normalized);
            if ($contact === null) {
                $contactId = $contactRepo->create([
                    'email' => $normalized,
                    'status' => 'active',
                    'consent_status' => 'pending',
                ]);
            } else {
                $contactId = (int)$contact['id'];
            }

            $this->attachContact($listId, $contactId, ['subscription_status' => 'subscribed', 'source' => 'partners_auto']);
        }
    }

    /**
     * @return array<int, array{contact_id:int,email:string,subscription_status:string}>
     */
    public function contactsIndex(int $listId): array
    {
        $sql = 'SELECT lc.contact_id, LOWER(c.email) AS email, lc.subscription_status
                FROM audience_list_contacts lc
                INNER JOIN marketing_contacts c ON c.id = lc.contact_id
                WHERE lc.list_id = :list_id AND c.email IS NOT NULL AND TRIM(c.email) != ""';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':list_id' => $listId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $email = strtolower(trim((string)($row['email'] ?? '')));
            $contactId = (int)($row['contact_id'] ?? 0);
            if ($email === '' || $contactId <= 0) {
                continue;
            }
            $status = (string)($row['subscription_status'] ?? 'subscribed');
            $result[] = [
                'contact_id' => $contactId,
                'email' => $email,
                'subscription_status' => $status,
            ];
        }

        return $result;
    }

    public function detachContacts(int $listId, array $contactIds): void
    {
        $contactIds = array_values(array_filter(array_map('intval', $contactIds), static fn(int $id): bool => $id > 0));
        if ($contactIds === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($contactIds), '?'));
        $sql = 'DELETE FROM audience_list_contacts WHERE list_id = ? AND contact_id IN (' . $placeholders . ')';
        $stmt = $this->pdo->prepare($sql);
        $params = array_merge([$listId], $contactIds);
        $stmt->execute($params);
    }

    public function create(array $payload): int
    {
        $timestamps = now();
        $payload['created_at'] = $payload['created_at'] ?? $timestamps;
        $payload['updated_at'] = $payload['updated_at'] ?? $timestamps;

        $columns = array_keys($payload);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO audience_lists (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $payload['updated_at'] = now();
        $payload['id'] = $id;

        $assignments = [];
        foreach ($payload as $column => $value) {
            if ($column === 'id') {
                continue;
            }
            $assignments[] = sprintf('%s = :%s', $column, $column);
        }

        $sql = sprintf('UPDATE audience_lists SET %s WHERE id = :id', implode(', ', $assignments));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($payload));
    }

    public function attachContact(int $listId, int $contactId, array $options = []): void
    {
        $timestamp = now();
        $data = [
            'list_id' => $listId,
            'contact_id' => $contactId,
            'subscription_status' => $options['subscription_status'] ?? 'subscribed',
            'source' => $options['source'] ?? null,
            'subscribed_at' => $options['subscribed_at'] ?? $timestamp,
            'unsubscribed_at' => $options['unsubscribed_at'] ?? null,
            'consent_token' => $options['consent_token'] ?? null,
            'metadata' => $options['metadata'] ?? null,
            'created_at' => $options['created_at'] ?? $timestamp,
            'updated_at' => $timestamp,
        ];

        $sql = 'INSERT INTO audience_list_contacts (
                    list_id, contact_id, subscription_status, source, subscribed_at,
                    unsubscribed_at, consent_token, metadata, created_at, updated_at
                ) VALUES (
                    :list_id, :contact_id, :subscription_status, :source, :subscribed_at,
                    :unsubscribed_at, :consent_token, :metadata, :created_at, :updated_at
                )
                ON CONFLICT(list_id, contact_id) DO UPDATE SET
                    subscription_status = excluded.subscription_status,
                    source = excluded.source,
                    subscribed_at = excluded.subscribed_at,
                    unsubscribed_at = excluded.unsubscribed_at,
                    consent_token = COALESCE(excluded.consent_token, audience_list_contacts.consent_token),
                    metadata = COALESCE(excluded.metadata, audience_list_contacts.metadata),
                    updated_at = excluded.updated_at';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->prefix($data));
    }

    public function unsubscribe(int $listId, int $contactId, ?string $metadata = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE audience_list_contacts
             SET subscription_status = "unsubscribed",
                 unsubscribed_at = :timestamp,
                 metadata = COALESCE(:metadata, metadata),
                 updated_at = :timestamp
             WHERE list_id = :list_id AND contact_id = :contact_id'
        );

        $stmt->execute([
            ':timestamp' => now(),
            ':metadata' => $metadata,
            ':list_id' => $listId,
            ':contact_id' => $contactId,
        ]);
    }

    public function unsubscribeContactEverywhere(int $contactId, ?string $metadata = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE audience_list_contacts
             SET subscription_status = "unsubscribed",
                 unsubscribed_at = :timestamp,
                 metadata = CASE WHEN :metadata IS NULL THEN metadata ELSE :metadata END,
                 updated_at = :timestamp
             WHERE contact_id = :contact_id AND subscription_status != "unsubscribed"'
        );

        $stmt->execute([
            ':timestamp' => now(),
            ':metadata' => $metadata,
            ':contact_id' => $contactId,
        ]);
    }

	public function countContacts(int $listId): int
	{
		$stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audience_list_contacts WHERE list_id = :list_id');
		$stmt->execute([':list_id' => $listId]);
		return (int)($stmt->fetchColumn() ?: 0);
	}

    public function contacts(int $listId, ?string $status = null, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT c.*, lc.subscription_status, lc.source, lc.subscribed_at, lc.unsubscribed_at, lc.metadata
            FROM audience_list_contacts lc
            INNER JOIN marketing_contacts c ON c.id = lc.contact_id
            WHERE lc.list_id = :list_id';

        $hasStatusFilter = $status !== null;

        if ($hasStatusFilter) {
            $sql .= ' AND lc.subscription_status = :status';
        }

        $sql .= ' ORDER BY c.email ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':list_id', $listId, PDO::PARAM_INT);
        if ($hasStatusFilter) {
            $stmt->bindValue(':status', $status);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    /**
     * @return array<int, string> emails únicos inscritos
     */
    public function subscribedEmails(int $listId): array
    {
        $sql = 'SELECT LOWER(c.email) AS email
                FROM audience_list_contacts lc
                INNER JOIN marketing_contacts c ON c.id = lc.contact_id
                WHERE lc.list_id = :list_id AND lc.subscription_status = "subscribed" AND c.email IS NOT NULL AND TRIM(c.email) != ""';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':list_id' => $listId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $unique = [];
        foreach ($rows as $row) {
            $email = strtolower(trim((string)($row['email'] ?? '')));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $unique[$email] = $email;
        }

        return array_values($unique);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM audience_lists WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    private function prefix(array $data): array
    {
        $prefixed = [];
        foreach ($data as $key => $value) {
            $prefixed[':' . $key] = $value;
        }

        return $prefixed;
    }
}
