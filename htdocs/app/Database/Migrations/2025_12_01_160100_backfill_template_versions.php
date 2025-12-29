<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $this->ensureVersionTableExists($pdo);
        $this->backfillTemplates($pdo);
    }

    private function ensureVersionTableExists(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS template_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                template_id INTEGER NOT NULL,
                version INTEGER NOT NULL,
                label TEXT NULL,
                status TEXT NOT NULL DEFAULT "draft",
                source_format TEXT NOT NULL DEFAULT "html",
                subject TEXT NULL,
                preview_text TEXT NULL,
                body_html TEXT NULL,
                body_text TEXT NULL,
                body_mjml TEXT NULL,
                blocks_schema TEXT NULL,
                data_schema TEXT NULL,
                testing_settings TEXT NULL,
                checksum TEXT NULL,
                notes TEXT NULL,
                created_by INTEGER NULL,
                published_by INTEGER NULL,
                published_at INTEGER NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
            )'
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_template_versions_template ON template_versions(template_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_template_versions_status ON template_versions(status)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_template_versions_unique ON template_versions(template_id, version)');
    }

    private function backfillTemplates(\PDO $pdo): void
    {
        $stmt = $pdo->prepare('SELECT id, subject, preview_text, body_html, body_text, created_at, updated_at, latest_version_id FROM templates');
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $templateId = (int)$row['id'];

            if ($this->templateHasVersions($pdo, $templateId)) {
                if ((int)($row['latest_version_id'] ?? 0) === 0) {
                    $latest = $this->fetchLatestVersionId($pdo, $templateId);
                    if ($latest !== null) {
                        $this->setLatestVersionId($pdo, $templateId, $latest);
                    }
                }
                continue;
            }

            $timestamp = (int)($row['updated_at'] ?? $row['created_at'] ?? time());
            $insert = $pdo->prepare(
                'INSERT INTO template_versions (
                    template_id, version, label, status, source_format, subject, preview_text, body_html, body_text, body_mjml,
                    blocks_schema, data_schema, testing_settings, checksum, notes, created_by, published_by, published_at, created_at, updated_at
                ) VALUES (
                    :template_id, :version, :label, :status, :source_format, :subject, :preview_text, :body_html, :body_text, NULL,
                    NULL, NULL, NULL, NULL, NULL, NULL, NULL, :published_at, :created_at, :updated_at
                )'
            );

            $insert->execute([
                ':template_id' => $templateId,
                ':version' => 1,
                ':label' => 'Versão inicial',
                ':status' => 'published',
                ':source_format' => 'html',
                ':subject' => $row['subject'] ?? null,
                ':preview_text' => $row['preview_text'] ?? null,
                ':body_html' => $row['body_html'] ?? null,
                ':body_text' => $row['body_text'] ?? null,
                ':published_at' => $timestamp,
                ':created_at' => $timestamp,
                ':updated_at' => $timestamp,
            ]);

            $versionId = (int)$pdo->lastInsertId();
            $this->setLatestVersionId($pdo, $templateId, $versionId);
        }
    }

    private function templateHasVersions(\PDO $pdo, int $templateId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM template_versions WHERE template_id = :template_id LIMIT 1');
        $stmt->execute([':template_id' => $templateId]);
        return (bool)$stmt->fetchColumn();
    }

    private function fetchLatestVersionId(\PDO $pdo, int $templateId): ?int
    {
        $stmt = $pdo->prepare('SELECT id FROM template_versions WHERE template_id = :template_id ORDER BY (published_at IS NULL), published_at DESC, updated_at DESC LIMIT 1');
        $stmt->execute([':template_id' => $templateId]);
        $result = $stmt->fetchColumn();
        return $result === false ? null : (int)$result;
    }

    private function setLatestVersionId(\PDO $pdo, int $templateId, int $versionId): void
    {
        $stmt = $pdo->prepare('UPDATE templates SET latest_version_id = :version_id WHERE id = :template_id');
        $stmt->execute([
            ':version_id' => $versionId,
            ':template_id' => $templateId,
        ]);
    }
};
