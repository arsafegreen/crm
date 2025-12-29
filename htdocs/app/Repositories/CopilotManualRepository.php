<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;
use RuntimeException;

final class CopilotManualRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::instance();
    }

    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT m.*, (
                SELECT COUNT(1) FROM copilot_manual_chunks c WHERE c.manual_id = m.id
             ) AS total_chunks
             FROM copilot_manuals m
             ORDER BY m.created_at DESC'
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function find(int $manualId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM copilot_manuals WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $manualId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function create(array $data, array $chunks): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO copilot_manuals (title, description, filename, storage_path, mime_type, size_bytes, content_preview, is_active, created_at, updated_at)
                 VALUES (:title, :description, :filename, :storage_path, :mime_type, :size_bytes, :content_preview, :is_active, :created_at, :updated_at)'
            );

            $stmt->execute([
                ':title' => $data['title'],
                ':description' => $data['description'],
                ':filename' => $data['filename'],
                ':storage_path' => $data['storage_path'],
                ':mime_type' => $data['mime_type'],
                ':size_bytes' => $data['size_bytes'],
                ':content_preview' => $data['content_preview'],
                ':is_active' => $data['is_active'],
                ':created_at' => $data['created_at'],
                ':updated_at' => $data['updated_at'],
            ]);

            $manualId = (int)$this->pdo->lastInsertId();

            if ($chunks !== []) {
                $chunkStmt = $this->pdo->prepare(
                    'INSERT INTO copilot_manual_chunks (manual_id, chunk_index, content, tokens_estimate, created_at)
                     VALUES (:manual_id, :chunk_index, :content, :tokens_estimate, :created_at)'
                );

                foreach ($chunks as $chunk) {
                    $chunkStmt->execute([
                        ':manual_id' => $manualId,
                        ':chunk_index' => (int)$chunk['chunk_index'],
                        ':content' => (string)$chunk['content'],
                        ':tokens_estimate' => (int)$chunk['tokens_estimate'],
                        ':created_at' => (int)($chunk['created_at'] ?? $data['created_at']),
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw new RuntimeException('Falha ao salvar o manual IA: ' . $exception->getMessage(), 0, $exception);
        }

        $manual = $this->find($manualId);
        if ($manual === null) {
            throw new RuntimeException('Manual salvo não foi localizado.');
        }

        return $manual;
    }

    public function delete(int $manualId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM copilot_manuals WHERE id = :id');
        $stmt->execute([':id' => $manualId]);
    }

    public function searchChunks(array $keywords, int $limit = 6): array
    {
        $limit = max(1, min(12, $limit));
        $conditions = ['m.is_active = 1'];
        $params = [];

        $keywords = array_values(array_filter(array_map(static function ($keyword): string {
            $normalized = trim(mb_strtolower((string)$keyword));
            return mb_strlen($normalized) >= 4 ? $normalized : '';
        }, $keywords)));

        if ($keywords !== []) {
            $likeParts = [];
            foreach ($keywords as $index => $keyword) {
                $placeholder = ':kw' . $index;
                $likeParts[] = "LOWER(c.content) LIKE $placeholder";
                $params[$placeholder] = '%' . $keyword . '%';
            }
            $conditions[] = '(' . implode(' OR ', $likeParts) . ')';
        }

        $where = implode(' AND ', $conditions);

        $sql = 'SELECT c.*, m.title
                FROM copilot_manual_chunks c
                INNER JOIN copilot_manuals m ON m.id = c.manual_id
                WHERE ' . $where . '
                ORDER BY
                    CASE WHEN c.tokens_estimate = 0 THEN 1 ELSE 0 END DESC,
                    c.tokens_estimate ASC,
                    c.chunk_index ASC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows !== false ? $rows : [];
    }

    public function count(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(1) FROM copilot_manuals WHERE is_active = 1');
        return (int)$stmt->fetchColumn();
    }
}
